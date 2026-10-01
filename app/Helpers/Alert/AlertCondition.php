<?php

namespace App\Helpers\Alert;

use RuntimeException;

class AlertCondition
{
    public const OPERATORS = ['=', '!=', '>', '>=', '<', '<='];

    public const ON_EMPTY = ['ok', 'triggered', 'error'];

    public static function evaluate(array $condition, int $rowCount, ?array $firstRow): array
    {
        $kind = $condition['kind'] ?? 'rows';
        $onEmpty = $condition['on_empty'] ?? 'ok';

        if ($rowCount === 0) {
            return match ($onEmpty) {
                'triggered' => ['triggered' => true, 'value' => 0, 'message' => 'Результат запроса пуст.'],
                'error' => throw new RuntimeException('Результат запроса пуст, а это считается ошибкой условия.'),
                default => ['triggered' => false, 'value' => 0, 'message' => null],
            };
        }

        return match ($kind) {
            'rows' => self::evaluateRows($condition, $rowCount),
            'value' => self::evaluateValue($condition, $firstRow),
            default => throw new RuntimeException("Неизвестный вид условия: {$kind}."),
        };
    }

    private static function evaluateRows(array $condition, int $rowCount): array
    {
        $op = $condition['op'] ?? '>';
        $threshold = (float) ($condition['threshold'] ?? 0);

        return [
            'triggered' => self::compare((float) $rowCount, $op, $threshold),
            'value' => $rowCount,
            'message' => null,
        ];
    }

    private static function evaluateValue(array $condition, ?array $firstRow): array
    {
        $column = $condition['column'] ?? null;

        if (!$column) {
            throw new RuntimeException('Для условия по значению не указана колонка.');
        }

        if (!$firstRow || !array_key_exists($column, $firstRow)) {
            throw new RuntimeException("В результате запроса нет колонки «{$column}».");
        }

        $raw = $firstRow[$column];

        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            throw new RuntimeException("Значение колонки «{$column}» не является числом: ".json_encode($raw));
        }

        $op = $condition['op'] ?? '>';
        $threshold = (float) ($condition['threshold'] ?? 0);

        return [
            'triggered' => self::compare((float) $raw, $op, $threshold),
            'value' => $raw,
            'message' => null,
        ];
    }

    private static function compare(float $value, string $op, float $threshold): bool
    {
        if (!in_array($op, self::OPERATORS, true)) {
            throw new RuntimeException("Неизвестный оператор условия: {$op}.");
        }

        return match ($op) {
            '=' => abs($value - $threshold) < 1e-9,
            '!=' => abs($value - $threshold) >= 1e-9,
            '>' => $value > $threshold,
            '>=' => $value >= $threshold,
            '<' => $value < $threshold,
            '<=' => $value <= $threshold,
        };
    }
}
