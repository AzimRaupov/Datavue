<?php

namespace App\Helpers\Widget;

class WidgetOutputValidator
{
    /**
     * Точка входа. $type — slug виджета (widget.name), $data — декодированный (array) вывод скрипта.
     * Возвращает массив ошибок. Пустой массив = валидно.
     */
    public function validate(string $type, mixed $data): array
    {
        if (!is_array($data)) {
            return ["Вывод не является валидным JSON-объектом/массивом"];
        }

        return match ($type) {
            'multi-series-trend' => $this->validateSeriesWithLabels($data, 'labels'),
            'bar'                => $this->validateSeriesWithLabels($data, 'categories'),
            'scatter-plot'       => $this->validateSeriesWithLabels($data, 'categories'),
            'radar'              => $this->validateSeriesWithLabels($data, 'categories'),
            'pie-chart'          => $this->validatePieLike($data),
            'donut-chart'        => $this->validatePieLike($data),
            'mini-counters'      => $this->validateMiniCounters($data),
            'table'              => $this->validateTable($data),
            default              => ["Неизвестный тип виджета: {$type}"],
        };
    }

    /**
     * multi-series-trend / bar / scatter-plot:
     * series: [{ name: string, data: [number,...] }], + labels|categories: [string,...]
     */
    private function validateSeriesWithLabels(array $data, string $axisKey): array
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
                if (!is_array($serie)) {
                    $errors[] = "'series[{$i}]' должен быть объектом";
                    continue;
                }

                if (!array_key_exists('name', $serie) || !is_string($serie['name']) || trim($serie['name']) === '') {
                    $errors[] = "'series[{$i}].name' обязателен и должен быть непустой строкой";
                }

                if (!array_key_exists('data', $serie) || !is_array($serie['data'])) {
                    $errors[] = "'series[{$i}].data' обязателен и должен быть массивом чисел";
                    continue;
                }

                foreach ($serie['data'] as $j => $value) {
                    if (!is_int($value) && !is_float($value)) {
                        $errors[] = "'series[{$i}].data[{$j}]' должен быть числом (int|float)";
                    }
                }

                if ($axisCount !== null && count($serie['data']) !== $axisCount) {
                    $errors[] = "Количество значений в 'series[{$i}].data' (" . count($serie['data']) .
                        ") не совпадает с количеством '{$axisKey}' ({$axisCount})";
                }
            }
        }

        return $errors;
    }

    /**
     * pie-chart / donut-chart:
     * series: [number,...], labels: [string,...], count(series) == count(labels)
     */
    private function validatePieLike(array $data): array
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

        if (!array_key_exists('labels', $data) || !is_array($data['labels'])) {
            $errors[] = "Поле 'labels' отсутствует или не является массивом";
        } else {
            foreach ($data['labels'] as $i => $label) {
                if (!is_string($label) || trim($label) === '') {
                    $errors[] = "'labels[{$i}]' должен быть непустой строкой";
                }
            }
        }

        if (is_array($data['series'] ?? null) && is_array($data['labels'] ?? null)
            && count($data['series']) !== count($data['labels'])) {
            $errors[] = "Количество элементов 'series' (" . count($data['series']) .
                ") не совпадает с количеством 'labels' (" . count($data['labels']) . ")";
        }

        return $errors;
    }

    /**
     * mini-counters:
     * counters: [{ name: string (обязателен, непустой), value: number (обязателен), prefix?: string, suffix?: string }]
     */
    private function validateMiniCounters(array $data): array
    {
        $errors = [];

        if (!array_key_exists('counters', $data) || !is_array($data['counters'])) {
            $errors[] = "Поле 'counters' отсутствует или не является массивом";
            return $errors;
        }

        if (empty($data['counters'])) {
            $errors[] = "Поле 'counters' пустое";
        }

        foreach ($data['counters'] as $i => $counter) {   // ← было: foreach ($data as $i => $counter)
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
     * table:
     * headers: [string,...], rows: [[string|int|float,...],...], count(row) == count(headers) для каждой строки
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
                $errors[] = "'rows[{$i}]' содержит " . count($row) .
                    " значений, ожидается {$headersCount} (по количеству headers)";
            }

            foreach ($row as $j => $value) {
                if (!is_string($value) && !is_int($value) && !is_float($value)) {
                    $errors[] = "'rows[{$i}][{$j}]' должен быть string|int|float";
                }
            }
        }

        return $errors;
    }
}
