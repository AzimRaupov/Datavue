<?php

namespace App\Helpers\Widget;

use RuntimeException;

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

    public const SHAPE_COLUMNS = [
        self::SHAPE_SERIES_VALUES => ['label', 'value'],
        self::SHAPE_SERIES_MATRIX => ['series', 'category', 'value'],
        self::SHAPE_POINTS => ['series', 'x', 'y'],
        self::SHAPE_COUNTERS => ['name', 'value'],
        self::SHAPE_ROWS => [],
    ];

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

    public static function shapeFor(string $family): string
    {
        return self::FAMILY_SHAPES[$family]
            ?? throw new RuntimeException("Для семейства «{$family}» не задана форма данных.");
    }

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

    private function buildFromMatrix(string $family, ?string $type, array $rows, array $presentation): array
    {

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

        $seriesNames = array_map('strval', array_keys($seriesSeen));
        $categories = array_map('strval', array_keys($categoriesSeen));

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

                $item['kind'] = $kinds[$name] ?? 'column';
            }

            $series[] = $item;
        }

        if ($family === 'heatmap') {
            return ['series' => $series];
        }

        $axisKey = $family === 'line' ? 'labels' : 'categories';

        return ['series' => $series, $axisKey => $categories];
    }

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

        return $value === null || $value === '' ? '—' : (string) $value;
    }

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
