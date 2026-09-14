<?php

namespace App\Helpers\DataSource;

use RuntimeException;

class SqlParameterBinder
{

    public const TYPE_DATE = 'date';
    public const TYPE_INT = 'int';
    public const TYPE_FLOAT = 'float';
    public const TYPE_STRING = 'string';

    public function __construct(private bool $supportsBindings = true)
    {
    }

    public function apply(string $sql, array $values, array $types = []): array
    {
        $bindings = [];

        $replace = function (string $chunk) use ($values, $types, &$bindings): string {

            return preg_replace_callback(

                '/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/',
                function (array $match) use ($values, $types, &$bindings) {
                    $name = $match[1];

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
                $chunk
            );
        };

        $parts = preg_split(
            "/('(?:\\\\.|''|[^'\\\\])*')/",
            $sql,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ($parts === false) {
            return ['sql' => $replace($sql), 'bindings' => $bindings];
        }

        $result = '';

        foreach ($parts as $index => $part) {

            $result .= $index % 2 === 1 ? $part : $replace($part);
        }

        return ['sql' => $result, 'bindings' => $bindings];
    }

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

    private function castDate(mixed $value): string
    {
        $value = trim((string) $value);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}(\s\d{2}:\d{2}(:\d{2})?)?$/', $value)) {
            throw new RuntimeException("Некорректная дата: «{$value}». Ожидается ГГГГ-ММ-ДД.");
        }

        return $value;
    }

    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'".str_replace("'", "''", (string) $value)."'";
    }
}
