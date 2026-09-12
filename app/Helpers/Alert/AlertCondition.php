<?php

namespace App\Helpers\Alert;

use RuntimeException;

/**
 * Решает «сработало или нет» по результату SQL-запроса алерта (mode
 * builder|sql). Режим python условие решает сам код — сюда не заходит.
 *
 * Декларация (dashboard_widgets.condition, тот же формат хранится у алерта):
 *
 *   { "kind": "rows",  "op": ">",  "threshold": 0 }
 *   { "kind": "value", "op": "<",  "threshold": 100, "column": "balance" }
 *
 * Пустой результат — не угадывается, а явно задан автором через on_empty:
 * "ok" | "triggered" | "error". Без этого поведение по умолчанию пришлось бы
 * выбирать за автора, а «нет строк» равно легитимно значит и «всё хорошо»
 * (запросов с ошибками не было), и «плохо» (не было ни одной продажи).
 */
class AlertCondition
{
    public const OPERATORS = ['=', '!=', '>', '>=', '<', '<='];

    public const ON_EMPTY = ['ok', 'triggered', 'error'];

    /**
     * @return array{triggered: bool, value: mixed, message: ?string}
     *
     * @throws RuntimeException если результат нельзя сравнить с порогом
     */
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
