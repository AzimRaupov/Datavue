<?php

namespace App\Helpers\DataSource;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Сколько разных значений даст группировка по колонке и какой период
 * покрывают даты.
 *
 * Без этих чисел модель выбирает виджет вслепую: «по статусам» и «по
 * клиентам» для неё выглядят одинаково, хотя статусов пять (это доли целого),
 * а клиентов двести сорок (это только рейтинг с топ-N). Схема даёт лишь
 * типы колонок и число строк таблицы — этого мало, чтобы понять, что будет
 * читаемо на графике.
 */
class ColumnProfile
{
    /**
     * Таблицы крупнее не профилируем: COUNT(DISTINCT) — полный проход по
     * таблице, а генерация дашборда не должна ждать минуты. Модель тогда
     * обходится числом строк таблицы, как и раньше.
     */
    private const MAX_ROWS = 2_000_000;

    private const TEXT_TYPES = [
        'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'string', 'enum', 'bpchar',
        'character varying', 'character', 'bool', 'boolean',
    ];

    private const INTEGER_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'int2', 'int4', 'int8'];

    public static function annotate(ConnectionProviderRouter $router, array $scheme): array
    {
        $driver = $router->dataSource->type->name ?? null;

        foreach ($scheme as $table => $definition) {
            $columns = $definition['columns'] ?? null;

            if (!is_array($columns) || $columns === []) {
                continue;
            }

            if (($definition['count_rows'] ?? 0) > self::MAX_ROWS) {
                continue;
            }

            $select = [];
            $profiled = [];

            foreach ($columns as $name => $meta) {
                $kind = self::kindOf((string) ($meta['type'] ?? ''), (string) ($meta['key'] ?? ''));

                if ($kind === null) {
                    continue;
                }

                $index = count($profiled);
                $column = self::quote($driver, (string) $name);

                $select[] = "COUNT(DISTINCT {$column}) AS d{$index}";

                if ($kind === 'date') {
                    $select[] = "MIN({$column}) AS mn{$index}";
                    $select[] = "MAX({$column}) AS mx{$index}";
                }

                $profiled[$index] = [(string) $name, $kind];
            }

            if ($select === []) {
                continue;
            }

            try {
                $rows = ReadOnlySqlGuard::normalizeRows(
                    $router->query('SELECT '.implode(', ', $select).' FROM '.self::quote($driver, (string) $table))
                );
            } catch (Throwable $e) {
                Log::info('ColumnProfile: таблица не профилирована', ['table' => $table, 'error' => $e->getMessage()]);

                continue;
            }

            $row = array_change_key_case($rows[0] ?? [], CASE_LOWER);

            foreach ($profiled as $index => [$name, $kind]) {
                $scheme[$table]['columns'][$name]['distinct'] = (int) ($row["d{$index}"] ?? 0);

                $from = $row["mn{$index}"] ?? null;
                $to = $row["mx{$index}"] ?? null;

                if ($kind === 'date' && $from !== null && $to !== null) {
                    $scheme[$table]['columns'][$name]['range'] = substr((string) $from, 0, 10).' .. '.substr((string) $to, 0, 10);
                }
            }
        }

        return $scheme;
    }

    /**
     * Профилируем то, по чему группируют: текст, даты, флаги и целые без
     * ключа (оценка 1–5, год). Суммы и цены — это метрики, их число разных
     * значений ничего не говорит о выборе виджета.
     */
    private static function kindOf(string $type, string $key): ?string
    {
        if (in_array($key, ['PRI', 'UNI'], true)) {
            return null;
        }

        $normalized = strtolower(trim(preg_replace('/\(.*\)/', '', $type) ?? $type));

        if (str_starts_with($normalized, 'date') || str_starts_with($normalized, 'timestamp')) {
            return 'date';
        }

        if (in_array($normalized, self::TEXT_TYPES, true)) {
            return 'value';
        }

        $base = preg_replace('/\s+unsigned$/', '', $normalized);

        return in_array($base, self::INTEGER_TYPES, true) ? 'value' : null;
    }

    private static function quote(?string $driver, string $identifier): string
    {
        return $driver === 'mysql'
            ? '`'.str_replace('`', '``', $identifier).'`'
            : '"'.str_replace('"', '""', $identifier).'"';
    }
}
