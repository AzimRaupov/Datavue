<?php

namespace App\Helpers\DataSource;

use RuntimeException;

/**
 * Подстановка значений фильтров в запрос виджета.
 *
 * Два пути, потому что провайдеры разные:
 *
 *  - MySQL, PostgreSQL, SQLite умеют подготовленные выражения: значение
 *    уходит отдельно от текста запроса и в SQL не попадает никогда.
 *
 *  - DuckDB выполняется через CLI (`duckdb -json -c "<запрос>"`), и параметры
 *    туда передать нечем: сигнатура query() их принимает, но молча теряет.
 *    Это было бы дырой ровно того сорта, от которой мы уходим, поэтому для
 *    DuckDB значение подставляется в текст — но ТОЛЬКО после приведения
 *    к объявленному типу. Дата обязана выглядеть как дата, число становится
 *    числом, строка экранируется. Произвольный текст в запрос не попадает.
 *
 * Именованные плейсхолдеры (:date_from) приводятся к позиционным (?) —
 * так одинаково работают все драйверы Laravel.
 */
class SqlParameterBinder
{
    /** Типы, которые может объявить фильтр. */
    public const TYPE_DATE = 'date';
    public const TYPE_INT = 'int';
    public const TYPE_FLOAT = 'float';
    public const TYPE_STRING = 'string';

    public function __construct(private bool $supportsBindings = true)
    {
    }

    /**
     * Заменяет именованные плейсхолдеры значениями.
     *
     * @param array<string, mixed> $values Значения по именам параметров
     * @param array<string, string> $types Тип каждого параметра
     *
     * @return array{sql: string, bindings: array<int, mixed>}
     */
    public function apply(string $sql, array $values, array $types = []): array
    {
        $bindings = [];

        // Плейсхолдеры перебираем в порядке появления в тексте: для позиционных
        // параметров важен именно он.
        $result = preg_replace_callback(
            // (?<!:) — чтобы приведение типа PostgreSQL (col::date)
            // не было принято за плейсхолдер.
            '/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/',
            function (array $match) use ($values, $types, &$bindings) {
                $name = $match[1];

                // Незнакомый плейсхолдер оставляем как есть: возможно, это
                // приведение типа в PostgreSQL (`col::date`), а не параметр.
                if (!array_key_exists($name, $values)) {
                    return $match[0];
                }

                $value = $this->cast($values[$name], $types[$name] ?? self::TYPE_STRING);

                if ($this->supportsBindings) {
                    $bindings[] = $value;

                    return '?';
                }

                return $this->literal($value);
            },
            $sql
        );

        return ['sql' => $result, 'bindings' => $bindings];
    }

    /**
     * Приводит значение к объявленному типу.
     *
     * Это и есть защита для DuckDB: значение, не прошедшее приведение,
     * до запроса не доходит.
     */
    public function cast(mixed $value, string $type): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            self::TYPE_DATE => $this->castDate($value),
            self::TYPE_INT => (int) $value,
            self::TYPE_FLOAT => (float) $value,
            default => (string) $value,
        };
    }

    /**
     * Дата принимается только в виде ГГГГ-ММ-ДД (при необходимости со временем).
     * Всё остальное — отказ, а не попытка угадать.
     */
    private function castDate(mixed $value): string
    {
        $value = trim((string) $value);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}(\s\d{2}:\d{2}(:\d{2})?)?$/', $value)) {
            throw new RuntimeException("Некорректная дата: «{$value}». Ожидается ГГГГ-ММ-ДД.");
        }

        return $value;
    }

    /**
     * Литерал для драйверов без подготовленных выражений.
     *
     * Вызывается только после cast(): сюда попадает либо число, либо строка,
     * прошедшая проверку типа.
     */
    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // Одинарная кавычка удваивается — стандартное экранирование SQL.
        return "'".str_replace("'", "''", (string) $value)."'";
    }
}
