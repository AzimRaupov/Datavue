<?php

namespace App\Helpers\Widget;

use App\Helpers\Ai\WidgetQueryAi;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use Illuminate\Support\Facades\Log;

/**
 * Готовит содержимое виджета: SQL-спецификацию вместо Python-скрипта.
 *
 * Три уровня проверки ДО сохранения — в этом главное отличие от прежнего
 * пути. Раньше сгенерированный скрипт сохранялся как есть, и о его
 * неработоспособности узнавали при первой отрисовке у пользователя.
 *
 *   1. Безопасность — только SELECT/WITH, без «;», с лимитом (ReadOnlySqlGuard).
 *   2. Синтаксис и существование колонок — подтверждает сама база (LIMIT 1).
 *   3. Форма — вернулись ли колонки, которых ждёт выбранная форма.
 *
 * Не прошло — ошибка уходит обратно модели дословно, и та чинит запрос.
 */
class WidgetSpecGenerator
{
    /** Сколько раз пробуем починить запрос перед тем, как признать неудачу. */
    private const MAX_ATTEMPTS = 3;

    private WidgetQueryAi $ai;
    private WidgetQueryRunner $runner;

    public function __construct(private DataSource $dataSource)
    {
        $this->ai = new WidgetQueryAi($dataSource);
        $this->runner = new WidgetQueryRunner($dataSource);
    }

    /**
     * @param array $tablesScheme Схема отобранных таблиц
     *
     * @return array{ok: bool, spec: ?array, filters: array, error: ?string, attempts: int}
     */
    public function generate(
        DashboardWidget $widget,
        array $tablesScheme,
        ?array $brokenSpec = null,
        ?string $initialError = null
    ): array {
        $family = $widget->widget->name;
        $type = $widget->widgetType->name ?? null;
        $instruction = (string) $widget->instruction;

        // Если пришли на починку — стартуем сразу с известной ошибкой,
        // а не тратим первую попытку на повторение того же запроса.
        $spec = $brokenSpec;
        $error = $initialError;
        $chosenFilters = [];

        // Кандидаты отбираются механически: обязательные сюда не попадают,
        // датовые — только если в схеме есть дата. Модель получает короткий
        // список, а не весь каталог.
        $candidates = WidgetFilterResolver::candidates($family, $tablesScheme);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {

            $isRepair = $error !== null;

            $result = $isRepair
                ? $this->ai->repair($instruction, $family, $type, $tablesScheme, $spec ?? [], $error, $candidates)
                : $this->ai->generate($instruction, $family, $type, $tablesScheme, $candidates);

            // Сбой связи с API — не ошибка запроса. Повторяем тот же шаг,
            // не переключаясь в режим починки: иначе модель получила бы
            // бессмысленное «модель не ответила» вместо реальной ошибки SQL.
            if (!empty($result['api_error'])) {
                Log::warning('WidgetSpecGenerator: обращение к модели не удалось', [
                    'widget_id' => $widget->id,
                    'attempt' => $attempt,
                    'error' => $result['api_error'],
                ]);

                // Небольшая пауза: чаще всего это ограничение частоты.
                usleep(1_500_000);

                continue;
            }

            if (empty($result['spec'])) {
                $error = $result['message'] ?? 'Модель не вернула запрос.';
                continue;
            }

            $spec = $result['spec'];
            $chosenFilters = $result['filters'] ?? [];

            // Форму не доверяем модели: она известна по семейству виджета,
            // и подмена здесь ломала бы раскладку.
            $spec['shape'] = WidgetShapeMapper::shapeFor($family);

            $check = $this->validate($spec, $family, $type);

            if ($check['ok']) {
                // Обязательные фильтры семейства добавляются здесь же —
                // они не зависят от ответа модели.
                WidgetFilterResolver::apply($widget, $family, $chosenFilters);

                return [
                    'ok' => true,
                    'spec' => $spec,
                    'filters' => $chosenFilters,
                    'error' => null,
                    'attempts' => $attempt,
                ];
            }

            $error = $check['error'];

            Log::info('WidgetSpecGenerator: попытка не прошла проверку', [
                'widget_id' => $widget->id,
                'attempt' => $attempt,
                'error' => $error,
            ]);
        }

        return [
            'ok' => false,
            'spec' => $spec,
            'filters' => [],
            'error' => $error,
            'attempts' => self::MAX_ATTEMPTS,
        ];
    }

    /**
     * Починка виджета, который не прошёл проверку при отрисовке.
     *
     * Ключевое отличие от generate(): модель получает СЛОМАННУЮ спецификацию
     * и ТЕКСТ ОШИБКИ. Раньше review вызывал обычную генерацию с теми же
     * входными данными — модель закономерно повторяла ту же ошибку, и починка
     * не работала. В Python-пути ошибка и код передавались, поэтому он и чинил.
     *
     * @return array{ok: bool, spec: ?array, filters: array, error: ?string, attempts: int}
     */
    public function repair(
        DashboardWidget $widget,
        array $tablesScheme,
        array $brokenSpec,
        string $error
    ): array {
        return $this->generate($widget, $tablesScheme, $brokenSpec, $error);
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    private function validate(array $spec, string $family, ?string $type): array
    {
        $queries = $spec['queries'] ?? [];

        if (!is_array($queries) || $queries === []) {
            return ['ok' => false, 'error' => 'В спецификации нет ни одного запроса.'];
        }

        // Уровни 1 и 2: безопасность и синтаксис проверяет сама база —
        // и по каждому запросу отдельно, иначе ошибка во втором счётчике
        // всплыла бы только при отрисовке у пользователя.
        $columns = [];

        foreach ($queries as $name => $sql) {
            $probe = $this->runner->probe($sql);

            if (!$probe['ok']) {
                return [
                    'ok' => false,
                    'error' => count($queries) > 1
                        ? "Запрос «{$name}»: ".$probe['error']
                        : $probe['error'],
                ];
            }

            // Колонки берём из первого запроса, вернувшего строки: все
            // запросы формы обязаны отдавать одинаковый набор колонок.
            if ($columns === [] && ($probe['columns'] ?? []) !== []) {
                $columns = array_map('strval', $probe['columns']);
            }
        }

        // Пустой результат — не повод отклонять запрос: данных может просто
        // не быть за выбранный период. Но и форму тогда не проверить.
        if ($columns === []) {
            return ['ok' => true, 'error' => null];
        }

        // Уровень 3: те ли колонки вернулись.
        $required = $this->requiredColumns($spec['shape'], $type);

        $missing = array_diff($required, $columns);

        if ($missing !== []) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'Запрос вернул колонки [%s], а форма «%s» требует [%s]. Отсутствуют: %s. '
                    . 'Задай имена через AS.',
                    implode(', ', $columns),
                    $spec['shape'],
                    implode(', ', $required),
                    implode(', ', $missing)
                ),
            ];
        }

        // Уровень 4: осмысленность результата, а не только его форма.
        $sense = $this->validateShapeSense($spec, $family, $type);

        if (!$sense['ok']) {
            return $sense;
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Ловит перепутанные местами series и category.
     *
     * Формально такой запрос корректен: колонки на месте, типы верные — все
     * предыдущие проверки он проходит. Но «продажи по странам», где страна
     * попала в series, разворачивается в 21 ряд по одному значению: каждая
     * страна своим цветом, ось из одного деления. График нечитаем, а понять
     * это по колонкам нельзя — только по данным.
     *
     * @return array{ok: bool, error: ?string}
     */
    private function validateShapeSense(array $spec, string $family, ?string $type): array
    {
        if (($spec['shape'] ?? null) !== WidgetShapeMapper::SHAPE_SERIES_MATRIX) {
            return ['ok' => true, 'error' => null];
        }

        try {
            $run = $this->runner->run(
                spec: $spec,
                family: $family,
                type: $type,
                sample: true
            );
        } catch (\Throwable) {
            // Проверка вспомогательная: не смогли посмотреть — не мешаем.
            return ['ok' => true, 'error' => null];
        }

        if (!($run['ok'] ?? false)) {
            return ['ok' => true, 'error' => null];
        }

        $seriesCount = count($run['data']['series'] ?? []);
        $axis = $run['data']['categories'] ?? $run['data']['labels'] ?? [];

        // Много рядов при единственном делении оси — почти наверняка подмена.
        // Порог в три ряда: два-три ряда на одну точку иногда осмысленны
        // (сравнение показателей на дату), двадцать — никогда.
        if ($seriesCount > 3 && count($axis) <= 1) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'Запрос вернул %d рядов и всего %d делений оси — значения из '
                    . 'колонки series должны быть в category. То, что сравнивают '
                    . '(страны, месяцы, товары), кладётся в category, а series для '
                    . 'одиночного графика — строковая константа, например '
                    . "SELECT 'Выручка' AS series, country AS category, SUM(...) AS value.",
                    $seriesCount,
                    count($axis)
                ),
            ];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Обязательные колонки формы с поправкой на вариант отрисовки:
     * bubble требует третье число, вариант с прогрессом — процент.
     *
     * @return array<int, string>
     */
    private function requiredColumns(string $shape, ?string $type): array
    {
        $columns = WidgetShapeMapper::SHAPE_COLUMNS[$shape] ?? [];

        if ($shape === WidgetShapeMapper::SHAPE_POINTS && $type === 'bubble') {
            $columns[] = 'z';
        }

        if ($shape === WidgetShapeMapper::SHAPE_COUNTERS && $type === 'with-progress') {
            $columns[] = 'percent';
        }

        return $columns;
    }
}
