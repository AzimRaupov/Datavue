<?php

namespace App\Helpers\Widget;

use RuntimeException;

/**
 * Раскладывает плоские строки SQL во вложенную схему виджета.
 *
 * Это ядро перехода с Python на SQL. Раньше вложенность собирала модель —
 * руками, заново для каждого виджета. Самый показательный пример, тепловая
 * карта: три запроса и двойной цикл со сводкой, где легко забыть заполнить
 * нулями отсутствующие пары или перепутать порядок категорий между рядами.
 *
 * Теперь SQL возвращает нормализованные строки, а сборку делает этот класс —
 * один раз, детерминированно и под тестами.
 *
 * ФОРМЫ (shape) — как читать строки:
 *
 *   series_values   label, value                — плоский список
 *   series_matrix   series, category, value     — сводка по рядам и категориям
 *   points          series, x, y [, z]          — точки
 *   counters        name, value [, prefix, suffix, percent]
 *   rows            любые колонки как есть
 *
 * Форма определяет ЧТЕНИЕ строк, семейство виджета — СБОРКУ результата.
 * Поэтому одна форма обслуживает несколько семейств: series_matrix одинаково
 * читается для bar, line, radar, combo и heatmap, а собирается по-разному.
 */
class WidgetShapeMapper
{
    public const SHAPE_SERIES_VALUES = 'series_values';
    public const SHAPE_SERIES_MATRIX = 'series_matrix';
    public const SHAPE_POINTS = 'points';
    public const SHAPE_COUNTERS = 'counters';
    public const SHAPE_ROWS = 'rows';

    public const SHAPES = [
        self::SHAPE_SERIES_VALUES,
        self::SHAPE_SERIES_MATRIX,
        self::SHAPE_POINTS,
        self::SHAPE_COUNTERS,
        self::SHAPE_ROWS,
    ];

    /**
     * Какая форма нужна каждому семейству.
     *
     * Ключ — имя семейства (widget.name). Используется и при генерации
     * (что просить у модели), и при проверке спецификации.
     */
    public const FAMILY_SHAPES = [
        'mini-counters' => self::SHAPE_COUNTERS,
        'table' => self::SHAPE_ROWS,
        'pie' => self::SHAPE_SERIES_VALUES,
        'radial' => self::SHAPE_SERIES_VALUES,
        'funnel' => self::SHAPE_SERIES_VALUES,
        'treemap' => self::SHAPE_SERIES_VALUES,
        'map' => self::SHAPE_SERIES_VALUES,
        'bar' => self::SHAPE_SERIES_MATRIX,
        'line' => self::SHAPE_SERIES_MATRIX,
        'radar' => self::SHAPE_SERIES_MATRIX,
        'combo' => self::SHAPE_SERIES_MATRIX,
        'heatmap' => self::SHAPE_SERIES_MATRIX,
        'scatter' => self::SHAPE_POINTS,
    ];

    /**
     * Колонки, которые обязан вернуть SQL для каждой формы.
     * Проверяются до сохранения спецификации — ошибка в именах колонок
     * должна всплывать сразу, а не кривым графиком через минуту.
     */
    public const SHAPE_COLUMNS = [
        self::SHAPE_SERIES_VALUES => ['label', 'value'],
        self::SHAPE_SERIES_MATRIX => ['series', 'category', 'value'],
        self::SHAPE_POINTS => ['series', 'x', 'y'],
        self::SHAPE_COUNTERS => ['name', 'value'],
        self::SHAPE_ROWS => [],
    ];

    /**
     * @param array<int, object|array> $rows Строки основного запроса
     * @param array $presentation Оформление, которого нет в данных
     *                            (kind у рядов combo, prefix/suffix у счётчиков)
     *
     * @return array Готовая структура под схему виджета
     */
    public function map(
        string $family,
        ?string $type,
        string $shape,
        array $rows,
        array $presentation = []
    ): array {
        $rows = array_map([$this, 'toArray'], $rows);

        return match ($shape) {
            self::SHAPE_COUNTERS => $this->buildCounters($rows, $type, $presentation),
            self::SHAPE_ROWS => $this->buildRows($rows),
            self::SHAPE_SERIES_VALUES => $this->buildFromValues($family, $type, $rows),
            self::SHAPE_SERIES_MATRIX => $this->buildFromMatrix($family, $type, $rows, $presentation),
            self::SHAPE_POINTS => $this->buildPoints($rows, $type),
            default => throw new RuntimeException("Неизвестная форма данных: {$shape}"),
        };
    }

    /** Форма, которую нужно просить у модели для этого семейства. */
    public static function shapeFor(string $family): string
    {
        return self::FAMILY_SHAPES[$family]
            ?? throw new RuntimeException("Для семейства «{$family}» не задана форма данных.");
    }

    // ---------------------------------------------------------------
    // Сборка по семействам
    // ---------------------------------------------------------------

    /**
     * Плоский список label/value.
     *
     * Пять семейств хотят одни и те же данные, но в разной упаковке:
     * pie и funnel — двумя параллельными массивами, treemap — списком
     * объектов x/y, map — списком объектов code/value.
     */
    private function buildFromValues(string $family, ?string $type, array $rows): array
    {
        $labels = [];
        $values = [];

        foreach ($rows as $row) {
            $labels[] = $this->stringOf($row, 'label');
            $values[] = $this->numberOf($row, 'value');
        }

        return match ($family) {
            'treemap' => ['series' => [[
                'data' => array_map(
                    fn ($label, $value) => ['x' => $label, 'y' => $value],
                    $labels,
                    $values
                ),
            ]]],

            'map' => ['series' => array_map(
                fn ($label, $value) => ['code' => $label, 'value' => $value],
                $labels,
                $values
            )],

            default => ['series' => $values, 'labels' => $labels],
        };
    }

    /**
     * Сводка: строки (ряд, категория, значение) → матрица.
     *
     * Ключевой момент — заполнение пропусков. Если для пары (ряд, категория)
     * строки не пришло, туда должен встать 0: длина data обязана совпадать
     * с длиной оси во ВСЕХ рядах, иначе график съезжает. Раньше это писала
     * модель, и именно здесь она чаще всего ошибалась.
     */
    private function buildFromMatrix(string $family, ?string $type, array $rows, array $presentation): array
    {
        // Порядок сохраняем тот, в котором прислал SQL: за сортировку отвечает
        // ORDER BY в запросе, а не этот класс. Уникальность держим на ключах
        // ассоциативного массива:in_array по растущему списку давал O(n²) —
        // на пяти тысячах строк это миллионы сравнений при каждой отрисовке.
        $seriesSeen = [];
        $categoriesSeen = [];
        $matrix = [];

        foreach ($rows as $row) {
            $series = $this->stringOf($row, 'series');
            $category = $this->stringOf($row, 'category');

            $seriesSeen[$series] = true;
            $categoriesSeen[$category] = true;

            $matrix[$series][$category] = $this->numberOf($row, 'value');
        }

        // strval обязателен: PHP превращает числовой ключ «2024» в int,
        // и подписи оси уехали бы на фронт числами вместо строк.
        $seriesNames = array_map('strval', array_keys($seriesSeen));
        $categories = array_map('strval', array_keys($categoriesSeen));

        // radar в варианте polar-area рисуется плоским списком, как pie.
        if ($family === 'radar' && $type === 'polar-area') {
            $first = $seriesNames[0] ?? null;

            return [
                'series' => array_map(
                    fn ($category) => $matrix[$first][$category] ?? 0,
                    $categories
                ),
                'labels' => $categories,
            ];
        }

        $kinds = $presentation['series_kinds'] ?? [];

        $series = [];

        foreach ($seriesNames as $name) {
            $data = array_map(
                fn ($category) => $matrix[$name][$category] ?? 0,
                $categories
            );

            if ($family === 'heatmap') {
                // Тепловая карта хранит подпись оси в самой точке.
                $series[] = [
                    'name' => $name,
                    'data' => array_map(
                        fn ($category) => ['x' => $category, 'y' => $matrix[$name][$category] ?? 0],
                        $categories
                    ),
                ];

                continue;
            }

            $item = ['name' => $name, 'data' => $data];

            if ($family === 'combo') {
                // Чем рисовать ряд — столбцами или линией — из данных не следует.
                // Это оформление, оно приходит в спецификации.
                $item['kind'] = $kinds[$name] ?? 'column';
            }

            $series[] = $item;
        }

        if ($family === 'heatmap') {
            return ['series' => $series];
        }

        // bar/radar/combo подписывают ось как categories, line — как labels.
        $axisKey = $family === 'line' ? 'labels' : 'categories';

        return ['series' => $series, $axisKey => $categories];
    }

    /**
     * Точки. Вариант bubble требует третье число — размер точки.
     */
    private function buildPoints(array $rows, ?string $type): array
    {
        $withSize = $type === 'bubble';
        $grouped = [];

        foreach ($rows as $row) {
            $name = $this->stringOf($row, 'series');

            $point = [$this->numberOf($row, 'x'), $this->numberOf($row, 'y')];

            if ($withSize) {
                $point[] = $this->numberOf($row, 'z');
            }

            $grouped[$name][] = $point;
        }

        $series = [];

        foreach ($grouped as $name => $points) {
            $series[] = ['name' => (string) $name, 'data' => $points];
        }

        return ['series' => $series];
    }

    /**
     * Счётчики. prefix/suffix — оформление: их можно задать и в SQL
     * (отдельными колонками), и в спецификации сразу на все счётчики.
     */
    private function buildCounters(array $rows, ?string $type, array $presentation): array
    {
        $withProgress = $type === 'with-progress';
        $defaults = $presentation['counters'] ?? [];

        $counters = [];

        foreach ($rows as $index => $row) {
            $name = $this->stringOf($row, 'name');
            $preset = $defaults[$index] ?? $defaults[$name] ?? [];

            $counter = [
                'name' => $name,
                'value' => $this->numberOf($row, 'value'),
                'prefix' => (string) ($row['prefix'] ?? $preset['prefix'] ?? ''),
                'suffix' => (string) ($row['suffix'] ?? $preset['suffix'] ?? ''),
            ];

            if ($withProgress) {
                $counter['percent'] = $this->numberOf($row, 'percent');
            }

            $counters[] = $counter;
        }

        return ['counters' => $counters];
    }

    /**
     * Таблица: колонки как есть. Заголовки берём из имён колонок первой
     * строки — так они совпадают с псевдонимами в SQL, и аналитик правит
     * подписи прямо в запросе через AS.
     */
    private function buildRows(array $rows): array
    {
        if (empty($rows)) {
            return ['headers' => [], 'rows' => []];
        }

        $headers = array_keys($rows[0]);

        return [
            'headers' => $headers,
            'rows' => array_map(
                fn ($row) => array_map(
                    fn ($header) => $row[$header] ?? null,
                    $headers
                ),
                $rows
            ),
        ];
    }

    // ---------------------------------------------------------------
    // Чтение значений
    // ---------------------------------------------------------------

    /** Строки приходят объектами (DB::select) — приводим к массиву. */
    private function toArray(mixed $row): array
    {
        if (is_array($row)) {
            return $row;
        }

        return json_decode(json_encode($row), true) ?: [];
    }

    private function stringOf(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        // NULL в группировке — обычное дело (клиент без страны).
        // Показывать пустую подпись хуже, чем честное «—».
        return $value === null || $value === '' ? '—' : (string) $value;
    }

    /**
     * Числа из разных драйверов приходят по-разному: MySQL отдаёт SUM()
     * строкой, DuckDB — числом, decimal может прийти как "1234.50".
     * Приводим к числу здесь, иначе фронт получит строку и график не построится.
     */
    private function numberOf(array $row, string $key): int|float
    {
        $value = $row[$key] ?? 0;

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (!is_numeric($value)) {
            return 0;
        }

        $number = $value + 0;

        return is_float($number) && floor($number) == $number && abs($number) < PHP_INT_MAX
            ? (int) $number
            : $number;
    }
}
