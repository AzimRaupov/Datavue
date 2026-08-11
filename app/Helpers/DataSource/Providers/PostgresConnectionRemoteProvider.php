<?php

namespace App\Helpers\DataSource\Providers;

/**
 * Источник данных PostgreSQL.
 *
 * Отличия от MySQL, которые здесь и описаны: идентификаторы цитируются двойными
 * кавычками, список таблиц и колонки берутся из information_schema, внешние ключи
 * — через связку table_constraints/key_column_usage/constraint_column_usage.
 *
 * Работает в пределах схемы (по умолчанию public): в PostgreSQL база данных
 * делится на схемы, и без фильтра в выдачу попали бы служебные таблицы.
 */
class PostgresConnectionRemoteProvider extends AbstractSqlConnectionProvider
{
    public string $host;
    public int|string $port;
    public string $username;
    public ?string $password;
    public string $database;
    public string $schema;

    public function __construct(
        $host,
        $port,
        $username,
        $password,
        $database,
        $schema = 'public'
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->database = $database;
        $this->schema = $schema ?: 'public';
    }

    protected function connectionConfig(): array
    {
        return [
            'driver' => 'pgsql',
            'host' => $this->host,
            'port' => $this->port ?: 5432,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'search_path' => $this->schema,
            'sslmode' => 'prefer',
        ];
    }

    protected function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function showTables(): array
    {
        $rows = $this->query(
            "
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = ?
              AND table_type = 'BASE TABLE'
            ORDER BY table_name
            ",
            [$this->schema]
        );

        return collect($rows)
            ->map(fn ($row) => ((array) $row)['table_name'] ?? null)
            ->filter()
            ->values()
            ->toArray();
    }

    public function showColumns(string $tableName): array
    {
        /*
        | key приводим к виду MySQL ("PRI"/"UNI"/""): базовый класс опирается на
        | него, когда решает, годится ли колонка как цель связи.
        */
        $rows = $this->query(
            "
            SELECT
                c.column_name,
                c.data_type,
                c.character_maximum_length,
                c.numeric_precision,
                c.numeric_scale,
                c.is_nullable,
                c.column_default,
                CASE
                    WHEN pk.column_name IS NOT NULL THEN 'PRI'
                    WHEN uq.column_name IS NOT NULL THEN 'UNI'
                    ELSE ''
                END AS column_key
            FROM information_schema.columns c
            LEFT JOIN (
                SELECT kcu.column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                  ON kcu.constraint_name = tc.constraint_name
                 AND kcu.table_schema = tc.table_schema
                WHERE tc.constraint_type = 'PRIMARY KEY'
                  AND tc.table_schema = ?
                  AND tc.table_name = ?
            ) pk ON pk.column_name = c.column_name
            LEFT JOIN (
                SELECT kcu.column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                  ON kcu.constraint_name = tc.constraint_name
                 AND kcu.table_schema = tc.table_schema
                WHERE tc.constraint_type = 'UNIQUE'
                  AND tc.table_schema = ?
                  AND tc.table_name = ?
            ) uq ON uq.column_name = c.column_name
            WHERE c.table_schema = ?
              AND c.table_name = ?
            ORDER BY c.ordinal_position
            ",
            [$this->schema, $tableName, $this->schema, $tableName, $this->schema, $tableName]
        );

        return collect($rows)
            ->map(function ($row) {
                $row = (array) $row;

                return [
                    'column_name' => $row['column_name'] ?? null,
                    'type' => $this->formatType($row),
                    'nullable' => strtoupper((string) ($row['is_nullable'] ?? 'YES')),
                    'key' => $row['column_key'] ?? '',
                    'default' => $row['column_default'] ?? null,
                ];
            })
            ->filter(fn ($column) => $column['column_name'])
            ->values()
            ->toArray();
    }

    protected function getForeignKeyRelations(): array
    {
        $rows = $this->query(
            "
            SELECT
                tc.table_name      AS from_table,
                kcu.column_name    AS from_column,
                ccu.table_name     AS to_table,
                ccu.column_name    AS to_column
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON kcu.constraint_name = tc.constraint_name
             AND kcu.table_schema = tc.table_schema
            JOIN information_schema.constraint_column_usage ccu
              ON ccu.constraint_name = tc.constraint_name
             AND ccu.table_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_schema = ?
            ",
            [$this->schema]
        );

        return collect($rows)
            ->map(fn ($row) => (array) $row)
            ->filter(fn ($relation) => !empty($relation['from_table'])
                && !empty($relation['from_column'])
                && !empty($relation['to_table'])
                && !empty($relation['to_column']))
            ->map(fn ($relation) => [
                'from_table' => $relation['from_table'],
                'from_column' => $relation['from_column'],
                'to_table' => $relation['to_table'],
                'to_column' => $relation['to_column'],
            ])
            ->values()
            ->toArray();
    }

    /**
     * Собирает тип в привычном виде: character varying(255), numeric(10,2).
     *
     * Базовый класс сравнивает совместимость типов, отрезая скобки, поэтому
     * форма записи важна только для читаемости схемы моделью.
     */
    private function formatType(array $row): string
    {
        $type = $row['data_type'] ?? 'unknown';

        if (!empty($row['character_maximum_length'])) {
            return $type . '(' . $row['character_maximum_length'] . ')';
        }

        if (!empty($row['numeric_precision']) && $type === 'numeric') {
            $scale = $row['numeric_scale'] ?? 0;

            return $type . '(' . $row['numeric_precision'] . ',' . $scale . ')';
        }

        return $type;
    }
}
