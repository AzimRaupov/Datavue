<?php

namespace App\Helpers\Widget;

use App\Helpers\Ai\Dashboard\WidgetSpecAi;
use App\Helpers\Ai\WidgetQueryAi;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use Illuminate\Support\Facades\Log;

/**
 * Готовит содержимое виджета: спецификацию запроса вместо Python-скрипта.
 *
 * Два пути, и порядок между ними не случайный.
 *
 *   Основной — декларация. Модель отвечает на вопрос «что считать и в каком
 *   разрезе», а запрос собирает WidgetQueryComposer. Промпт короткий, ошибки
 *   в синтаксисе и диалекте невозможны в принципе, а колонки проверяются по
 *   схеме ещё до обращения к базе.
 *
 *   Запасной — SQL текстом. Нужен там, где декларации не хватает: связи
 *   нескольких таблиц, окна, подзапросы. Сюда попадают виджеты, которым
 *   назначено больше одной таблицы, и те, где модель сама подняла флаг.
 *
 * Проверяет результат в обоих случаях один и тот же WidgetSpecValidator —
 * тот, что стоит за конструктором. Правила не могут разойтись между путями,
 * потому что они физически одни.
 *
 * Не прошло — ошибка уходит обратно модели дословно, и та чинит СВОЙ ответ,
 * а не сочиняет заново.
 */
class WidgetSpecGenerator
{
    /** Сколько раз пробуем починить перед тем, как признать неудачу. */
    private const MAX_ATTEMPTS = 3;

    private WidgetSpecAi $planner;
    private WidgetQueryAi $sqlAi;
    private WidgetQueryComposer $composer;
    private WidgetSpecValidator $validator;

    public function __construct(private DataSource $dataSource)
    {
        $this->planner = new WidgetSpecAi();
        $this->sqlAi = new WidgetQueryAi($dataSource);
        $this->composer = new WidgetQueryComposer($dataSource);
        $this->validator = new WidgetSpecValidator($dataSource);
    }

    /**
     * @param array $tablesScheme Схема отобранных таблиц: таблица => колонки
     *
     * @return array{ok: bool, spec: ?array, mode: string, error: ?string, attempts: int, tokens: int}
     */
    public function generate(DashboardWidget $widget, array $tablesScheme): array
    {
        $family = $widget->widget->name;
        $type = $widget->widgetType->name ?? $widget->effectiveType()?->name;
        $instruction = (string) $widget->instruction;

        $schema = $this->compactSchema($tablesScheme);
        $slots = WidgetQueryComposer::slotsFor($family, $type);

        $spentTokens = 0;

        // Одна таблица — задача почти наверняка выражается настройками.
        // Несколько — нужны связи, и туда декларация не дотянется: идём
        // сразу текстом запроса, не тратя попытку впустую.
        if (count($schema) === 1) {
            $result = $this->viaBuilder($widget, $instruction, $family, $type, $schema, $slots);

            if ($result['ok'] || !$result['fallback']) {
                return $this->strip($result);
            }

            $spentTokens = $result['tokens'];

            Log::info('WidgetSpecGenerator: переходим на текстовый запрос', [
                'widget_id' => $widget->id,
                'reason' => $result['error'],
            ]);
        }

        return $this->strip(
            $this->viaSql($widget, $instruction, $family, $type, $tablesScheme, $spentTokens)
        );
    }

    /**
     * Починка виджета, который сломался при отрисовке.
     *
     * Ключевое отличие от generate(): модель получает СЛОМАННУЮ спецификацию
     * и текст ошибки. Без этого она закономерно повторяет прежний ответ.
     *
     * @return array{ok: bool, spec: ?array, mode: string, error: ?string, attempts: int, tokens: int}
     */
    public function repair(
        DashboardWidget $widget,
        array $tablesScheme,
        array $brokenSpec,
        string $error
    ): array {
        $family = $widget->widget->name;
        $type = $widget->widgetType->name ?? $widget->effectiveType()?->name;
        $instruction = (string) $widget->instruction;

        // Виджет, собранный декларацией, чинится декларацией: так модель
        // правит то, что сама выбрала, а не переписывает всё запросом.
        if (($brokenSpec['mode'] ?? null) === DashboardWidget::MODE_BUILDER) {
            $schema = $this->compactSchema($tablesScheme);
            $slots = WidgetQueryComposer::slotsFor($family, $type);

            $result = $this->viaBuilder(
                $widget,
                $instruction,
                $family,
                $type,
                $schema,
                $slots,
                $brokenSpec['builder'] ?? [],
                $error
            );

            if ($result['ok']) {
                return $this->strip($result);
            }
        }

        return $this->strip(
            $this->viaSql($widget, $instruction, $family, $type, $tablesScheme, 0, $brokenSpec, $error)
        );
    }

    // -----------------------------------------------------------------
    // Путь 1: декларация
    // -----------------------------------------------------------------

    /**
     * @return array{ok: bool, spec: ?array, mode: string, error: ?string, attempts: int, tokens: int, fallback: bool}
     */
    private function viaBuilder(
        DashboardWidget $widget,
        string $instruction,
        string $family,
        ?string $type,
        array $schema,
        array $slots,
        ?array $brokenBuilder = null,
        ?string $initialError = null
    ): array {
        $builder = $brokenBuilder;
        $error = $initialError;
        $tokens = 0;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $answer = $error !== null && $builder !== null
                ? $this->planner->repair($instruction, $family, $type, $schema, $slots, $builder, $error)
                : $this->planner->plan($instruction, $family, $type, $schema, $slots);

            $tokens += $answer['total_tokens'] ?? 0;

            // Сбой связи — не ошибка ответа: повторяем тот же шаг, иначе
            // модель получила бы «нет ответа» вместо реальной причины.
            if (!empty($answer['api_error'])) {
                Log::warning('WidgetSpecGenerator: модель не ответила', [
                    'widget_id' => $widget->id,
                    'attempt' => $attempt,
                    'error' => $answer['api_error'],
                ]);

                usleep(1_500_000);

                continue;
            }

            if (!empty($answer['needs_sql'])) {
                return $this->builderResult(false, null, $answer['message'] ?? 'Нужен запрос текстом', $attempt, $tokens, fallback: true);
            }

            if (!$answer['ok']) {
                $error = $answer['message'] ?? 'Модель не вернула настройки.';

                continue;
            }

            $builder = $answer['builder'];

            // Сборка — тоже проверка: колонки, типы и совместимость со слотами
            // виджета отсеиваются здесь, без обращения к базе.
            $composed = $this->composer->compose($builder, $family, $type);

            if (!$composed['ok']) {
                $error = $composed['errors'][0] ?? 'Не удалось собрать запрос.';

                Log::info('WidgetSpecGenerator: настройки не собрались', [
                    'widget_id' => $widget->id,
                    'attempt' => $attempt,
                    'error' => $error,
                ]);

                continue;
            }

            $spec = WidgetSpecValidator::build($family, $composed['sql'], $composed['presentation'] ?? []);
            $spec['mode'] = DashboardWidget::MODE_BUILDER;
            $spec['builder'] = $builder;

            $check = $this->validator->validate($spec, $family, $type);

            if ($check['ok']) {
                return $this->builderResult(true, $spec, null, $attempt, $tokens, fallback: false);
            }

            $error = $check['errors'][0] ?? 'Запрос не прошёл проверку.';
        }

        // Настройками не вышло — пробуем текстом запроса.
        return $this->builderResult(false, null, $error, self::MAX_ATTEMPTS, $tokens, fallback: true);
    }

    // -----------------------------------------------------------------
    // Путь 2: запрос текстом
    // -----------------------------------------------------------------

    /**
     * @return array{ok: bool, spec: ?array, mode: string, error: ?string, attempts: int, tokens: int}
     */
    private function viaSql(
        DashboardWidget $widget,
        string $instruction,
        string $family,
        ?string $type,
        array $tablesScheme,
        int $tokensSoFar = 0,
        ?array $brokenSpec = null,
        ?string $initialError = null
    ): array {
        $spec = $brokenSpec;
        $error = $initialError;
        $tokens = $tokensSoFar;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $isRepair = $error !== null && $spec !== null;

            // Фильтры этому пути не передаются: их каталог не подключён,
            // и спрашивать о нём модель значит тратить токены впустую.
            $answer = $isRepair
                ? $this->sqlAi->repair($instruction, $family, $type, $tablesScheme, $spec, $error, [])
                : $this->sqlAi->generate($instruction, $family, $type, $tablesScheme, []);

            $tokens += $answer['total_tokens'] ?? 0;

            if (!empty($answer['api_error'])) {
                usleep(1_500_000);

                continue;
            }

            if (empty($answer['spec'])) {
                $error = $answer['message'] ?? 'Модель не вернула запрос.';

                continue;
            }

            $spec = $answer['spec'];

            // Форму не доверяем модели: она известна по семейству виджета,
            // и подмена здесь сломала бы раскладку.
            $spec['shape'] = WidgetShapeMapper::shapeFor($family);
            $spec['mode'] = DashboardWidget::MODE_SQL;

            $check = $this->validator->validate($spec, $family, $type);

            if ($check['ok']) {
                return [
                    'ok' => true,
                    'spec' => $spec,
                    'mode' => DashboardWidget::MODE_SQL,
                    'error' => null,
                    'attempts' => $attempt,
                    'tokens' => $tokens,
                ];
            }

            $error = $check['errors'][0] ?? 'Запрос не прошёл проверку.';

            Log::info('WidgetSpecGenerator: запрос не прошёл проверку', [
                'widget_id' => $widget->id,
                'attempt' => $attempt,
                'error' => $error,
            ]);
        }

        return [
            'ok' => false,
            'spec' => $spec,
            'mode' => DashboardWidget::MODE_SQL,
            'error' => $error,
            'attempts' => self::MAX_ATTEMPTS,
            'tokens' => $tokens,
        ];
    }

    // -----------------------------------------------------------------

    /**
     * Схема в виде «таблица => колонка => тип».
     *
     * Из полной схемы выбрасывается всё, что не нужно для выбора метрики
     * и разреза: связи, число строк, уверенность сопоставления. На виджет
     * это экономит больше половины промпта.
     *
     * @return array<string, array<string, string>>
     */
    private function compactSchema(array $tablesScheme): array
    {
        $schema = [];

        foreach ($tablesScheme as $table => $definition) {
            $columns = $definition['columns'] ?? $definition;

            if (!is_array($columns)) {
                continue;
            }

            foreach ($columns as $name => $meta) {
                if (is_int($name)) {
                    // Колонки могут прийти простым списком имён.
                    $schema[$table][(string) $meta] = 'unknown';

                    continue;
                }

                $schema[$table][$name] = is_array($meta)
                    ? (string) ($meta['type'] ?? 'unknown')
                    : (string) $meta;
            }
        }

        return $schema;
    }

    /**
     * @return array{ok: bool, spec: ?array, mode: string, error: ?string, attempts: int, tokens: int, fallback: bool}
     */
    private function builderResult(bool $ok, ?array $spec, ?string $error, int $attempts, int $tokens, bool $fallback): array
    {
        return [
            'ok' => $ok,
            'spec' => $spec,
            'mode' => DashboardWidget::MODE_BUILDER,
            'error' => $error,
            'attempts' => $attempts,
            'tokens' => $tokens,
            'fallback' => $fallback,
        ];
    }

    /**
     * Убирает служебный признак «нужен запасной путь» из ответа наружу.
     *
     * @return array{ok: bool, spec: ?array, mode: string, error: ?string, attempts: int, tokens: int}
     */
    private function strip(array $result): array
    {
        unset($result['fallback']);

        return $result;
    }
}
