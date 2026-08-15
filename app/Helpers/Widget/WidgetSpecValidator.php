<?php

namespace App\Helpers\Widget;

use App\Models\DataSource;
use Throwable;

/**
 * Проверяет спецификацию виджета до того, как она попадёт в базу.
 *
 * Спецификация — это запрос плюс оформление:
 *
 *   {
 *     "queries": { "main": "SELECT ... AS category, ... AS value FROM ..." },
 *     "shape": "series_matrix",
 *     "presentation": { ... }
 *   }
 *
 * Форму (shape) автор не задаёт: она следует из семейства виджета. Автор
 * отвечает только за запрос и за имена колонок в нём — это и есть контракт
 * между аналитиком и платформой.
 *
 * Проверка идёт тремя уровнями, и каждый следующий строже предыдущего:
 *
 *   1. Безопасность — только SELECT/WITH, без «;» и операций изменения данных.
 *   2. Выполнимость — база сама подтверждает синтаксис и существование
 *      колонок пробным запросом с LIMIT 1.
 *   3. Форма — вернулись ли те колонки, которых ждёт семейство виджета.
 *
 * Класс один на все входы: и конструктор, и (в будущем) генерация ИИ должны
 * проверять спецификацию одинаково. Разведи это по двум местам — правила
 * разойдутся, и на одном из путей появится дыра.
 */
class WidgetSpecValidator
{
    /**
     * Колонки, которые обязан вернуть запрос для семейства.
     *
     * Базовый набор задаёт форма, а вариант отрисовки может потребовать ещё
     * одну: пузырьковой диаграмме нужен третий размер, счётчику с прогрессом —
     * процент выполнения.
     *
     * @return array<int, string>
     */
    public static function requiredColumns(string $family, ?string $type = null): array
    {
        $shape = WidgetShapeMapper::shapeFor($family);

        $columns = WidgetShapeMapper::SHAPE_COLUMNS[$shape] ?? [];

        if ($shape === WidgetShapeMapper::SHAPE_POINTS && $type === 'bubble') {
            $columns[] = 'z';
        }

        if ($shape === WidgetShapeMapper::SHAPE_COUNTERS && $type === 'with-progress') {
            $columns[] = 'percent';
        }

        return $columns;
    }

    /**
     * Собирает спецификацию из того, что ввёл автор.
     *
     * Форму подставляем сами — доверять её выбор автору нельзя: раскладка
     * результата зависит от неё напрямую, и ошибка здесь ломает виджет
     * молча, уже на отрисовке.
     */
    public static function build(string $family, string $sql, array $presentation = []): array
    {
        $spec = [
            'queries' => ['main' => trim($sql)],
            'shape' => WidgetShapeMapper::shapeFor($family),
        ];

        if ($presentation !== []) {
            $spec['presentation'] = $presentation;
        }

        return $spec;
    }

    public function __construct(private DataSource $dataSource)
    {
    }

    /**
     * @return array{ok: bool, errors: array<int, string>, columns: array<int, string>}
     */
    public function validate(array $spec, string $family, ?string $type = null): array
    {
        $queries = $this->queriesOf($spec);

        if ($queries === []) {
            return $this->fail('В спецификации нет ни одного запроса.');
        }

        $runner = new WidgetQueryRunner($this->dataSource);

        $columns = [];

        foreach ($queries as $name => $sql) {
            try {
                $probe = $runner->probe($sql);
            } catch (Throwable $e) {
                return $this->fail($e->getMessage());
            }

            if (!($probe['ok'] ?? false)) {
                $error = self::cleanDatabaseError((string) $probe['error']);

                return $this->fail(
                    count($queries) > 1
                        ? "Запрос «{$name}»: ".$error
                        : $error
                );
            }

            // Колонки берём из первого запроса, который вернул строки: все
            // запросы одной спецификации обязаны отдавать одинаковый набор,
            // иначе их нельзя склеить в один результат.
            if ($columns === [] && ($probe['columns'] ?? []) !== []) {
                $columns = array_map('strval', $probe['columns']);
            }
        }

        // Пустой результат — не повод отклонять запрос: данных может просто
        // не быть за выбранный период. Но и форму тогда проверить нечем.
        if ($columns === []) {
            return ['ok' => true, 'errors' => [], 'columns' => []];
        }

        $required = self::requiredColumns($family, $type);
        $missing = array_diff($required, $columns);

        if ($missing !== []) {
            return $this->fail(sprintf(
                'Запрос вернул колонки [%s], а виджету «%s» нужны [%s]. Не хватает: %s. '
                . 'Задайте имена через AS — например SELECT country AS %s.',
                implode(', ', $columns),
                $family,
                implode(', ', $required),
                implode(', ', $missing),
                reset($missing)
            ), $columns);
        }

        return ['ok' => true, 'errors' => [], 'columns' => $columns];
    }

    /**
     * Оставляет от ошибки базы только то, что помогает автору починить запрос.
     *
     * Laravel дописывает к сообщению драйвера свой хвост с адресом сервера,
     * портом и именем базы, а следом — полный текст выполненного запроса
     * вместе с обёрткой проверки. Автору это ничего не объясняет, зато
     * реквизиты подключения к базе клиента уезжают в интерфейс и в логи
     * браузера. Причина ошибки — в первой части сообщения, её и оставляем.
     */
    public static function cleanDatabaseError(string $error): string
    {
        $clean = preg_replace('/\s*\(Connection:.*$/s', '', $error) ?? $error;

        return trim($clean) !== '' ? trim($clean) : $error;
    }

    /**
     * @return array<string, string>
     */
    private function queriesOf(array $spec): array
    {
        $queries = $spec['queries'] ?? $spec['query'] ?? null;

        if (is_string($queries)) {
            return trim($queries) === '' ? [] : ['main' => $queries];
        }

        if (!is_array($queries)) {
            return [];
        }

        $result = [];

        foreach ($queries as $name => $sql) {
            if (is_string($sql) && trim($sql) !== '') {
                $result[(string) $name] = $sql;
            }
        }

        return $result;
    }

    /**
     * @return array{ok: bool, errors: array<int, string>, columns: array<int, string>}
     */
    private function fail(string $error, array $columns = []): array
    {
        return ['ok' => false, 'errors' => [$error], 'columns' => $columns];
    }
}
