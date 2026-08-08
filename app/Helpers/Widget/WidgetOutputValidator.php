<?php

namespace App\Helpers\Widget;

class WidgetOutputValidator
{
    /**
     * Проверяет вывод python-скрипта против формы данных виджета.
     *
     * $family — имя семейства (widget.name), $type — выбранный вариант отрисовки
     * (widget_type.name). Тип важен там, где он переопределяет форму данных:
     * bubble требует третье число на точку, polar-area — плоский список,
     * with-progress — поле percent.
     *
     * Возвращает массив ошибок; пустой массив = вывод валиден.
     */
    public function validate(string $family, mixed $data, ?string $type = null): array
    {
        if (!is_array($data)) {
            return ["Вывод не является валидным JSON-объектом/массивом"];
        }

        return match ($family) {
            'mini-counters' => $this->validateMiniCounters($data, $type === 'with-progress'),
            'bar'           => $this->validateSeriesWithAxis($data, 'categories'),
            'line'          => $this->validateSeriesWithAxis($data, 'labels'),
            'combo'         => $this->validateCombo($data),
            'pie'           => $this->validateFlatSeries($data, 'labels'),
            'radial'        => $this->validateFlatSeries($data, 'labels'),
            'funnel'        => $this->validateFlatSeries($data, 'labels'),
            'table'         => $this->validateTable($data),
            'scatter'       => $this->validatePoints($data, $type === 'bubble' ? 3 : 2),
            'radar'         => $type === 'polar-area'
                ? $this->validateFlatSeries($data, 'labels')
                : $this->validateSeriesWithAxis($data, 'categories'),
            'heatmap'       => $this->validateMatrix($data),
            'treemap'       => $this->validateTreemap($data),
            'map'           => $this->validateMap($data),
            default         => ["Неизвестное семейство виджета: {$family}"],
        };
    }

    /**
     * bar / line / radar: series: [{ name: string, data: [number,...] }]
     * плюс ось категорий, длина которой совпадает с длиной каждого data.
     */
    private function validateSeriesWithAxis(array $data, string $axisKey): array
    {
        $errors = [];

        if (!array_key_exists('series', $data) || !is_array($data['series'])) {
            $errors[] = "Поле 'series' отсутствует или не является массивом";
        } elseif (empty($data['series'])) {
            $errors[] = "Поле 'series' пустое";
        }

        if (!array_key_exists($axisKey, $data) || !is_array($data[$axisKey])) {
            $errors[] = "Поле '{$axisKey}' отсутствует или не является массивом";
        }

        $axisCount = is_array($data[$axisKey] ?? null) ? count($data[$axisKey]) : null;

        if (is_array($data[$axisKey] ?? null)) {
            foreach ($data[$axisKey] as $i => $label) {
                if (!is_string($label) || trim($label) === '') {
                    $errors[] = "'{$axisKey}[{$i}]' должен быть непустой строкой";
                }
            }
        }

        if (is_array($data['series'] ?? null)) {
            foreach ($data['series'] as $i => $serie) {
                $errors = array_merge($errors, $this->validateSerie($serie, $i, $axisCount, $axisKey));
            }
        }

        return $errors;
    }

    /**
     * Один ряд вида { name, data: [number,...] }.
     */
    private function validateSerie(mixed $serie, int|string $i, ?int $axisCount, string $axisKey): array
    {
        $errors = [];

        if (!is_array($serie)) {
            return ["'series[{$i}]' должен быть объектом"];
        }

        if (!array_key_exists('name', $serie) || !is_string($serie['name']) || trim($serie['name']) === '') {
            $errors[] = "'series[{$i}].name' обязателен и должен быть непустой строкой";
        }

        if (!array_key_exists('data', $serie) || !is_array($serie['data'])) {
            return array_merge($errors, ["'series[{$i}].data' обязателен и должен быть массивом чисел"]);
        }

        foreach ($serie['data'] as $j => $value) {
            if (!is_int($value) && !is_float($value)) {
                $errors[] = "'series[{$i}].data[{$j}]' должен быть числом (int|float)";
            }
        }

        if ($axisCount !== null && count($serie['data']) !== $axisCount) {
            $errors[] = "Количество значений в 'series[{$i}].data' (".count($serie['data']).
                ") не совпадает с количеством '{$axisKey}' ({$axisCount})";
        }

        return $errors;
    }

    /**
     * combo: ряды с полем kind ("column" | "line"), обоих видов минимум по одному —
     * иначе это обычный bar или line, а не совмещённый график.
     */
    private function validateCombo(array $data): array
    {
        $errors = $this->validateSeriesWithAxis($data, 'categories');

        if (!is_array($data['series'] ?? null)) {
            return $errors;
        }

        $kinds = [];

        foreach ($data['series'] as $i => $serie) {
            $kind = is_array($serie) ? ($serie['kind'] ?? null) : null;

            if (!in_array($kind, ['column', 'line'], true)) {
                $errors[] = "'series[{$i}].kind' обязателен и должен быть \"column\" или \"line\"";
                continue;
            }

            $kinds[] = $kind;
        }

        if ($kinds && !in_array('line', $kinds, true)) {
            $errors[] = "У combo должен быть хотя бы один ряд с kind=\"line\" — иначе это обычный bar";
        }

        if ($kinds && !in_array('column', $kinds, true)) {
            $errors[] = "У combo должен быть хотя бы один ряд с kind=\"column\" — иначе это обычный line";
        }

        return $errors;
    }

    /**
     * pie / radial / funnel / polar-area: series: [number,...] + подписи той же длины.
     */
    private function validateFlatSeries(array $data, string $labelsKey): array
    {
        $errors = [];

        if (!array_key_exists('series', $data) || !is_array($data['series'])) {
            $errors[] = "Поле 'series' отсутствует или не является массивом";
        } else {
            if (empty($data['series'])) {
                $errors[] = "Поле 'series' пустое";
            }

            foreach ($data['series'] as $i => $value) {
                if (!is_int($value) && !is_float($value)) {
                    $errors[] = "'series[{$i}]' должен быть числом (int|float)";
                }
            }
        }

        if (!array_key_exists($labelsKey, $data) || !is_array($data[$labelsKey])) {
            $errors[] = "Поле '{$labelsKey}' отсутствует или не является массивом";
        } else {
            foreach ($data[$labelsKey] as $i => $label) {
                if (!is_string($label) || trim($label) === '') {
                    $errors[] = "'{$labelsKey}[{$i}]' должен быть непустой строкой";
                }
            }
        }

        if (is_array($data['series'] ?? null) && is_array($data[$labelsKey] ?? null)
            && count($data['series']) !== count($data[$labelsKey])) {
            $errors[] = "Количество элементов 'series' (".count($data['series']).
                ") не совпадает с количеством '{$labelsKey}' (".count($data[$labelsKey]).")";
        }

        return $errors;
    }

    /**
     * scatter / bubble: series: [{ name, data: [[x, y] | [x, y, z], ...] }].
     * $arity — сколько чисел в точке: 2 для точек, 3 для пузырьков.
     */
    private function validatePoints(array $data, int $arity): array
    {
        $errors = [];

        if (!array_key_exists('series', $data) || !is_array($data['series'])) {
            return ["Поле 'series' отсутствует или не является массивом"];
        }

        if (empty($data['series'])) {
            $errors[] = "Поле 'series' пустое";
        }

        foreach ($data['series'] as $i => $serie) {
            if (!is_array($serie)) {
                $errors[] = "'series[{$i}]' должен быть объектом";
                continue;
            }

            if (!array_key_exists('name', $serie) || !is_string($serie['name']) || trim($serie['name']) === '') {
                $errors[] = "'series[{$i}].name' обязателен и должен быть непустой строкой";
            }

            if (!array_key_exists('data', $serie) || !is_array($serie['data'])) {
                $errors[] = "'series[{$i}].data' обязателен и должен быть массивом точек";
                continue;
            }

            foreach ($serie['data'] as $j => $point) {
                if (!is_array($point) || count($point) !== $arity) {
                    $errors[] = "'series[{$i}].data[{$j}]' должен быть массивом из {$arity} чисел";
                    continue;
                }

                foreach (array_values($point) as $k => $value) {
                    if (!is_int($value) && !is_float($value)) {
                        $errors[] = "'series[{$i}].data[{$j}][{$k}]' должен быть числом (int|float)";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * heatmap: series: [{ name, data: [{ x: string, y: number }, ...] }].
     * Набор x обязан совпадать во всех рядах, иначе колонки матрицы разъедутся.
     */
    private function validateMatrix(array $data): array
    {
        $errors = [];

        if (!array_key_exists('series', $data) || !is_array($data['series'])) {
            return ["Поле 'series' отсутствует или не является массивом"];
        }

        if (empty($data['series'])) {
            $errors[] = "Поле 'series' пустое";
        }

        $firstColumns = null;

        foreach ($data['series'] as $i => $serie) {
            if (!is_array($serie)) {
                $errors[] = "'series[{$i}]' должен быть объектом";
                continue;
            }

            if (!array_key_exists('name', $serie) || !is_string($serie['name']) || trim($serie['name']) === '') {
                $errors[] = "'series[{$i}].name' обязателен и должен быть непустой строкой";
            }

            if (!array_key_exists('data', $serie) || !is_array($serie['data'])) {
                $errors[] = "'series[{$i}].data' обязателен и должен быть массивом ячеек";
                continue;
            }

            $columns = [];

            foreach ($serie['data'] as $j => $cell) {
                if (!is_array($cell) || !array_key_exists('x', $cell) || !array_key_exists('y', $cell)) {
                    $errors[] = "'series[{$i}].data[{$j}]' должен быть объектом с полями x и y";
                    continue;
                }

                if (!is_string($cell['x']) || trim($cell['x']) === '') {
                    $errors[] = "'series[{$i}].data[{$j}].x' должен быть непустой строкой";
                }

                if (!is_int($cell['y']) && !is_float($cell['y'])) {
                    $errors[] = "'series[{$i}].data[{$j}].y' должен быть числом (int|float)";
                }

                $columns[] = $cell['x'] ?? null;
            }

            if ($firstColumns === null) {
                $firstColumns = $columns;
            } elseif ($columns !== $firstColumns) {
                $errors[] = "Набор значений x в 'series[{$i}]' не совпадает с первым рядом — "
                    ."во всех рядах heatmap колонки должны идти одинаково";
            }
        }

        return $errors;
    }

    /**
     * treemap: series: [{ data: [{ x: string, y: number }, ...] }] — ровно один элемент.
     */
    private function validateTreemap(array $data): array
    {
        $errors = [];

        if (!array_key_exists('series', $data) || !is_array($data['series'])) {
            return ["Поле 'series' отсутствует или не является массивом"];
        }

        if (count($data['series']) !== 1) {
            $errors[] = "У treemap должен быть ровно один элемент 'series', получено ".count($data['series']);
        }

        foreach ($data['series'] as $i => $serie) {
            if (!is_array($serie) || !array_key_exists('data', $serie) || !is_array($serie['data'])) {
                $errors[] = "'series[{$i}].data' обязателен и должен быть массивом блоков";
                continue;
            }

            foreach ($serie['data'] as $j => $block) {
                if (!is_array($block) || !array_key_exists('x', $block) || !array_key_exists('y', $block)) {
                    $errors[] = "'series[{$i}].data[{$j}]' должен быть объектом с полями x и y";
                    continue;
                }

                if (!is_string($block['x']) || trim($block['x']) === '') {
                    $errors[] = "'series[{$i}].data[{$j}].x' должен быть непустой строкой";
                }

                if (!is_int($block['y']) && !is_float($block['y'])) {
                    $errors[] = "'series[{$i}].data[{$j}].y' должен быть числом (int|float)";
                }
            }
        }

        return $errors;
    }

    /**
     * mini-counters: counters: [{ name, value, percent?, prefix?, suffix? }].
     * percent обязателен только для типа with-progress — без него полосе
     * выполнения нечего показывать.
     */
    private function validateMiniCounters(array $data, bool $requirePercent = false): array
    {
        $errors = [];

        if (!array_key_exists('counters', $data) || !is_array($data['counters'])) {
            return ["Поле 'counters' отсутствует или не является массивом"];
        }

        if (empty($data['counters'])) {
            $errors[] = "Поле 'counters' пустое";
        }

        foreach ($data['counters'] as $i => $counter) {
            if (!is_array($counter)) {
                $errors[] = "'counters[{$i}]' должен быть объектом";
                continue;
            }

            if (!array_key_exists('name', $counter) || !is_string($counter['name']) || trim($counter['name']) === '') {
                $errors[] = "'counters[{$i}].name' обязателен и должен быть непустой строкой";
            }

            if (!array_key_exists('value', $counter) || (!is_int($counter['value']) && !is_float($counter['value']))) {
                $errors[] = "'counters[{$i}].value' обязателен и должен быть числом (int|float)";
            }

            if ($requirePercent) {
                if (!array_key_exists('percent', $counter)
                    || (!is_int($counter['percent']) && !is_float($counter['percent']))) {
                    $errors[] = "'counters[{$i}].percent' обязателен для типа with-progress и должен быть числом";
                }
            }

            if (array_key_exists('prefix', $counter) && $counter['prefix'] !== null && !is_string($counter['prefix'])) {
                $errors[] = "'counters[{$i}].prefix' должен быть строкой";
            }

            if (array_key_exists('suffix', $counter) && $counter['suffix'] !== null && !is_string($counter['suffix'])) {
                $errors[] = "'counters[{$i}].suffix' должен быть строкой";
            }
        }

        return $errors;
    }

    /**
     * map: series: [{ code: string (ISO alpha-2), value: number }].
     */
    private function validateMap(array $data): array
    {
        $errors = [];

        if (!array_key_exists('series', $data) || !is_array($data['series'])) {
            return ["Поле 'series' отсутствует или не является массивом"];
        }

        foreach ($data['series'] as $i => $item) {
            if (!is_array($item)) {
                $errors[] = "'series[{$i}]' должен быть объектом";
                continue;
            }

            if (!isset($item['code']) || !is_string($item['code']) || strlen(trim($item['code'])) !== 2) {
                $errors[] = "'series[{$i}].code' должен быть кодом страны из двух букв (ISO 3166-1 alpha-2)";
            }

            if (!array_key_exists('value', $item) || (!is_int($item['value']) && !is_float($item['value']))) {
                $errors[] = "'series[{$i}].value' обязателен и должен быть числом (int|float)";
            }
        }

        return $errors;
    }

    /**
     * table: headers: [string,...], rows: [[string|int|float,...],...],
     * длина каждой строки совпадает с количеством заголовков.
     */
    private function validateTable(array $data): array
    {
        $errors = [];

        if (!array_key_exists('headers', $data) || !is_array($data['headers'])) {
            $errors[] = "Поле 'headers' отсутствует или не является массивом";
        } else {
            foreach ($data['headers'] as $i => $header) {
                if (!is_string($header) || trim($header) === '') {
                    $errors[] = "'headers[{$i}]' должен быть непустой строкой";
                }
            }
        }

        if (!array_key_exists('rows', $data) || !is_array($data['rows'])) {
            $errors[] = "Поле 'rows' отсутствует или не является массивом";

            return $errors;
        }

        $headersCount = is_array($data['headers'] ?? null) ? count($data['headers']) : null;

        foreach ($data['rows'] as $i => $row) {
            if (!is_array($row)) {
                $errors[] = "'rows[{$i}]' должен быть массивом";
                continue;
            }

            if ($headersCount !== null && count($row) !== $headersCount) {
                $errors[] = "Количество значений в 'rows[{$i}]' (".count($row).
                    ") не совпадает с количеством 'headers' ({$headersCount})";
            }

            foreach ($row as $j => $cell) {
                if ($cell !== null && !is_string($cell) && !is_int($cell) && !is_float($cell)) {
                    $errors[] = "'rows[{$i}][{$j}]' должен быть строкой или числом";
                }
            }
        }

        return $errors;
    }
}
