<?php

namespace App\Helpers\DataSource\Providers;

use RuntimeException;

/**
 * Источник данных SQLite.
 *
 * База — это файл, поэтому хоста и пользователя нет, а метаданные берутся не из
 * information_schema (её в SQLite не существует), а из sqlite_master и
 * PRAGMA-запросов.
 */
class SqliteConnectionLocalProvider extends AbstractSqlConnectionProvider
{
    public string $path;

    public function __construct(?string $path)
    {
        $path = (string) $path;

        if ($path === '') {
            throw new RuntimeException('SQLite: не указан путь к файлу базы данных');
        }

        $this->path = $path;
    }

    protected function connectionConfig(): array
    {
        return [
            'driver' => 'sqlite',
            'database' => $this->path,
            'prefix' => '',
            // Источник пользователя открываем только на чтение: платформа
            // ничего в нём не меняет, а файл лежит рядом с его данными.
            'foreign_key_constraints' => false,
        ];
    }

    protected function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function check(): array
    {
        if (!is_file($this->path)) {
            return [
                'success' => false,
                'message' => "Файл базы данных не найден: {$this->path}",
            ];
        }

        return parent::check();
    }

    public function showTables(): array
    {
        $rows = $this->query(
            "
            SELECT name
            FROM sqlite_master
            WHERE type = 'table'
              AND name NOT LIKE 'sqlite_%'
            ORDER BY name
            "
        );

        return collect($rows)
            ->map(fn ($row) => ((array) $row)['name'] ?? null)
            ->filter()
            ->values()
            ->toArray();
    }

    public function showColumns(string $tableName): array
    {
        // PRAGMA не принимает биндинги, поэтому имя таблицы цитируем сами.
        $rows = $this->query(
            'PRAGMA table_info(' . $this->quoteIdentifier($tableName) . ')'
        );

        $uniqueColumns = $this->uniqueColumns($tableName);

        return collect($rows)
            ->map(function ($row) use ($uniqueColumns) {
                $row = (array) $row;
                $name = $row['name'] ?? null;

                // pk > 0 означает участие в первичном ключе, notnull — запрет NULL.
                $key = '';

                if (!empty($row['pk'])) {
                    $key = 'PRI';
                } elseif ($name !== null && in_array($name, $uniqueColumns, true)) {
                    $key = 'UNI';
                }

                return [
                    'column_name' => $name,
                    'type' => $row['type'] ?: 'unknown',
                    'nullable' => empty($row['notnull']) ? 'YES' : 'NO',
                    'key' => $key,
                    'default' => $row['dflt_value'] ?? null,
                ];
            })
            ->filter(fn ($column) => $column['column_name'])
            ->values()
            ->toArray();
    }

    protected function getForeignKeyRelations(): array
    {
        $relations = [];

        // В SQLite внешние ключи перечисляются отдельно для каждой таблицы.
        foreach ($this->showTables() as $table) {
            $rows = $this->query(
                'PRAGMA foreign_key_list(' . $this->quoteIdentifier($table) . ')'
            );

            foreach ($rows as $row) {
                $row = (array) $row;

                $toColumn = $row['to'] ?? null;

                // Ссылка без явной колонки указывает на первичный ключ цели.
                if ($toColumn === null && !empty($row['table'])) {
                    $toColumn = $this->primaryKeyOf($row['table']);
                }

                if (empty($row['from']) || empty($row['table']) || $toColumn === null) {
                    continue;
                }

                $relations[] = [
                    'from_table' => $table,
                    'from_column' => $row['from'],
                    'to_table' => $row['table'],
                    'to_column' => $toColumn,
                ];
            }
        }

        return $relations;
    }

    /**
     * Колонки, входящие в уникальные индексы по одной колонке.
     *
     * @return array<int, string>
     */
    private function uniqueColumns(string $tableName): array
    {
        $indexes = $this->query(
            'PRAGMA index_list(' . $this->quoteIdentifier($tableName) . ')'
        );

        $columns = [];

        foreach ($indexes as $index) {
            $index = (array) $index;

            if (empty($index['unique']) || empty($index['name'])) {
                continue;
            }

            $info = $this->query(
                'PRAGMA index_info(' . $this->quoteIdentifier($index['name']) . ')'
            );

            // Составной уникальный индекс не делает колонку уникальной саму по себе.
            if (count($info) !== 1) {
                continue;
            }

            $column = ((array) $info[0])['name'] ?? null;

            if ($column !== null) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    private function primaryKeyOf(string $tableName): ?string
    {
        foreach ($this->showColumns($tableName) as $column) {
            if (($column['key'] ?? '') === 'PRI') {
                return $column['column_name'];
            }
        }

        return null;
    }
}
