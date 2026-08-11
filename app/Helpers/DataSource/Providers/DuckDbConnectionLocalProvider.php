<?php

namespace App\Helpers\DataSource\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Collection;

class DuckDbConnectionLocalProvider
{
    public string $pathDb;

    /**
     * Кэш ключевых колонок, полученных из duckdb_constraints() и метаданных импорта.
     * Формат: ['table_name' => ['column_name' => 'PRI'|'UNI'|'MUL']]
     */
    private ?array $keyColumnsCache = null;

    public function __construct(string $pathDb)
    {
        $this->pathDb = $pathDb;
    }

    /**
     * Получить список таблиц
     */
    public function showTables(): array
    {
        $rows = $this->query('SHOW TABLES');

        return collect($rows)
            ->map(fn ($row) => $row->name ?? array_values((array) $row)[0] ?? null)
            ->filter(fn ($name) => !is_string($name) || !str_starts_with($name, '__datavue_'))
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Получить информацию о колонках таблицы
     */
    public function showColumns(string $tableName): array
    {
        $rows = $this->query('DESCRIBE ' . $this->quoteIdentifier($tableName));

        $keyColumns = $this->getKeyColumns()[$tableName] ?? [];

        return collect($rows)
            ->map(function ($row) use ($keyColumns) {
                $row = (array) $row;

                $columnName = $row['column_name'] ?? $row['Field'] ?? null;

                // Поле "key" из DESCRIBE у DuckDB ненадёжно (особенно для UNIQUE),
                // поэтому приоритет отдаём данным из duckdb_constraints().
                $key = $columnName !== null
                    ? ($keyColumns[$columnName] ?? ($row['key'] ?? $row['Key'] ?? ''))
                    : ($row['key'] ?? $row['Key'] ?? '');

                return [
                    'column_name' => $columnName,
                    'type'        => $row['column_type'] ?? $row['Type'] ?? 'unknown',
                    'nullable'    => $row['null'] ?? $row['Null'] ?? 'YES',
                    'key'         => $key,
                    'default'     => $row['default'] ?? $row['Default'] ?? null,
                    'extra'       => $row['extra'] ?? null,
                ];
            })
            ->filter(fn ($c) => !empty($c['column_name']))
            ->values()
            ->toArray();
    }

    public function check(): array
    {
        try {
            $process = Process::run([
                'duckdb',
                $this->pathDb,
                '-c',
                'SELECT 1 AS test_connection',
            ]);

            if ($process->successful()) {
                return [
                    'success' => true,
                    'message' => 'Подключение успешно',
                ];
            }

            return [
                'success' => false,
                'message' => $process->errorOutput() ?: 'Неизвестная ошибка',
            ];

        } catch (\Throwable $e) {
            Log::error('DuckDB check error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Выполнение SQL-запроса через DuckDB CLI
     */
    public function query(string $query, array $bindings = [])
    {
        $command = [
            'duckdb',
            $this->pathDb,
            '-json',
            '-c',
            $query,
        ];

        $process = Process::run($command);

        if ($process->successful()) {
            $output = trim($process->output());

            if (empty($output)) {
                return collect();
            }

            try {
                // false -> декодируем как объекты (stdClass), как это делает DB::select() у MySQL
                $decoded = json_decode($output, false, 512, JSON_THROW_ON_ERROR);

                if (is_array($decoded)) {
                    return collect($decoded);
                }

                return collect([$decoded]);

            } catch (\JsonException $e) {
                Log::error('DuckDB JSON parse error: ' . $e->getMessage(), [
                    'output' => $output,
                    'query'  => $query,
                ]);
                return collect();
            }
        }

        Log::error('DuckDB CLI Error', [
            'query'     => $query,
            'error'     => $process->errorOutput(),
            'exit_code' => $process->exitCode(),
        ]);

        return collect();
    }


    public function getSchema($tables_list = [], array $options = []): array
    {
        $tables = count($tables_list) > 0
            ? $tables_list
            : $this->showTables();

        $schema = [];

        $includeCountRows = in_array('count_rows', $options);
        $includeColumns = in_array('columns', $options);
        $includeRelations = isset($options['relations']);

        /*
        |--------------------------------------------------------------------------
        | Получаем все колонки таблиц
        |--------------------------------------------------------------------------
        */

        $allColumns = [];

        foreach ($tables as $tableName) {

            $columns = $this->showColumns($tableName);

            $tableColumns = [];

            foreach ($columns as $column) {

                $columnName = $column['column_name'] ?? null;

                if (!$columnName) {
                    continue;
                }

                $tableColumns[$columnName] = [
                    'type' => $column['type'] ?? 'unknown',
                    'nullable' => $column['nullable'] ?? 'YES',
                    'key' => $column['key'] ?? '',
                    'default' => $column['default'] ?? null,
                ];
            }

            $allColumns[$tableName] = $tableColumns;
        }

        /*
        |--------------------------------------------------------------------------
        | Определяем связи
        |--------------------------------------------------------------------------
        */

        $relations = [];

        if ($includeRelations) {

            $relationSchema = [];

            foreach ($allColumns as $tableName => $columns) {
                $relationSchema[$tableName] = [
                    'columns' => $columns,
                ];
            }

            $relations = $this->detectRelations($relationSchema);

            Log::info('DuckDbConnectionLocalProvider: обнаружено связей', [
                'count' => count($relations),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Формируем результат
        |--------------------------------------------------------------------------
        */

        foreach ($tables as $tableName) {

            $tableSchema = [];

            if ($includeCountRows) {
                $tableSchema['count_rows'] = $this->getTableCount($tableName);
            }

            if ($includeColumns) {

                $tableColumns = $allColumns[$tableName];

                if ($includeRelations) {

                    foreach ($relations as $relation) {

                        if ($relation['from_table'] === $tableName) {

                            $fromColumn = $relation['from_column'];

                            unset($tableColumns[$fromColumn]);
                        }
                    }
                }

                $tableSchema['columns'] = $tableColumns;
            }

            if ($includeRelations) {

                foreach ($relations as $relation) {

                    $fromTable = $relation['from_table'];
                    $fromColumn = $relation['from_column'];

                    if ($fromTable !== $tableName) {
                        continue;
                    }

                    if (!isset($allColumns[$fromTable][$fromColumn])) {
                        continue;
                    }

                    $columnData = $allColumns[$fromTable][$fromColumn];

                    $relationColumn = [];

                    foreach ($options['relations']['column'] ?? [] as $field) {
                        if (array_key_exists($field, $columnData)) {
                            $relationColumn[$field] = $columnData[$field];
                        }
                    }

                    $relationData = [
                        'table' => $relation['to_table'],
                        'column' => $relation['to_column'],
                        'confidence' => $relation['confidence'],
                        'match_rate' => $relation['match_rate'],
                    ];

                    $filteredRelation = [];

                    foreach ($options['relations']['relation'] ?? [] as $field) {
                        if (array_key_exists($field, $relationData)) {
                            $filteredRelation[$field] = $relationData[$field];
                        }
                    }

                    $relationColumn['relation'] = $filteredRelation;

                    $tableSchema['relations'][$fromColumn] = $relationColumn;
                }
            }

            $schema[$tableName] = $tableSchema;
        }

        return $schema;
    }


    private function getTableCount(string $tableName): int
    {
        $query = sprintf(
            'SELECT COUNT(*) AS count_rows FROM %s',
            $this->quoteIdentifier($tableName)
        );

        $result = $this->query($query);

        return (int) ($result[0]->count_rows ?? 0);
    }

    /**
     * Возвращает карту key-колонок по всем таблицам БД.
     * Сначала берём реальные DuckDB constraints, затем дополняем
     * метаданными, которые MySqlDataHandler сохраняет из ALTER TABLE.
     *
     * Формат: ['table_name' => ['column_name' => 'PRI'|'UNI'|'MUL', ...]]
     */
    private function getKeyColumns(): array
    {
        if ($this->keyColumnsCache !== null) {
            return $this->keyColumnsCache;
        }

        try {
            $rows = $this->query(
                "SELECT table_name, constraint_type, constraint_column_names
                 FROM duckdb_constraints()
                 WHERE constraint_type IN ('PRIMARY KEY', 'UNIQUE')"
            );
        } catch (\Throwable $e) {
            Log::warning('DuckDB key columns detection unavailable: ' . $e->getMessage());
            $rows = collect();
        }

        $keys = [];

        foreach ($rows as $row) {
            $row = (array) $row;

            $tableName = $row['table_name'] ?? null;
            $constraintType = $row['constraint_type'] ?? null;
            $columnNames = $row['constraint_column_names'] ?? null;

            if (!$tableName || !$constraintType || !$columnNames) {
                continue;
            }

            // DuckDB CLI сериализует LIST-колонку в JSON-массив,
            // но на всякий случай подстрахуемся, если пришла строка.
            if (!is_array($columnNames)) {
                $decoded = json_decode((string) $columnNames, true);
                $columnNames = is_array($decoded) ? $decoded : [$columnNames];
            }

            $marker = $constraintType === 'PRIMARY KEY' ? 'PRI' : 'UNI';

            foreach ($columnNames as $columnName) {

                if (!$columnName) {
                    continue;
                }

                // PRIMARY KEY приоритетнее UNIQUE, не перезаписываем
                if (($keys[$tableName][$columnName] ?? null) === 'PRI') {
                    continue;
                }

                $keys[$tableName][$columnName] = $marker;
            }
        }

        try {
            $metaRows = $this->query(
                'SELECT table_name, column_name, key_type
                 FROM "__datavue_keys"'
            );
        } catch (\Throwable $e) {
            $metaRows = collect();
        }

        foreach ($metaRows as $row) {
            $row = (array) $row;

            $tableName = $row['table_name'] ?? null;
            $columnName = $row['column_name'] ?? null;
            $keyType = $row['key_type'] ?? null;

            if (!$tableName || !$columnName || !in_array($keyType, ['PRI', 'UNI', 'MUL'], true)) {
                continue;
            }

            if (($keys[$tableName][$columnName] ?? null) === 'PRI') {
                continue;
            }

            if (($keys[$tableName][$columnName] ?? null) === 'UNI' && $keyType === 'MUL') {
                continue;
            }

            $keys[$tableName][$columnName] = $keyType;
        }

        return $this->keyColumnsCache = $keys;
    }

    /**
     * Определение связей между таблицами
     *
     * 1. Реальный FOREIGN KEY (через duckdb_constraints())
     * 2. Совпадение имени/типа колонок
     * 3. Проверка реальных значений через JOIN
     */
    private function detectRelations(array $schema): array
    {
        $relations = [];

        /*
        |--------------------------------------------------------------------------
        | 1. Получаем реальные FOREIGN KEY
        |--------------------------------------------------------------------------
        */

        $foreignKeys = $this->getForeignKeyRelations();

        foreach ($foreignKeys as $foreignKey) {

            $matchRate = $this->calculateMatchRate(
                $foreignKey['from_table'],
                $foreignKey['from_column'],
                $foreignKey['to_table'],
                $foreignKey['to_column']
            );

            $relations[] = [
                'from_table' => $foreignKey['from_table'],
                'from_column' => $foreignKey['from_column'],
                'to_table' => $foreignKey['to_table'],
                'to_column' => $foreignKey['to_column'],
                'confidence' => 'very_high',
                'match_rate' => $matchRate,
                'type' => 'foreign_key',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Проверяем связи по значениям
        |--------------------------------------------------------------------------
        */

        $tables = array_keys($schema);

        foreach ($tables as $fromTable) {

            foreach ($tables as $toTable) {

                if ($fromTable === $toTable) {
                    continue;
                }

                foreach ($schema[$fromTable]['columns'] as $fromColumn => $fromColumnData) {

                    foreach ($schema[$toTable]['columns'] as $toColumn => $toColumnData) {

                        if (!$this->areTypesCompatible($fromColumnData['type'], $toColumnData['type'])) {
                            continue;
                        }

                        $isTargetColumn = in_array($toColumnData['key'], ['PRI', 'UNI']);

                        if (!$isTargetColumn) {
                            continue;
                        }

                        $matchRate = $this->calculateMatchRate(
                            $fromTable,
                            $fromColumn,
                            $toTable,
                            $toColumn
                        );

                        if ($matchRate < 80) {
                            continue;
                        }

                        if ($this->relationExists($relations, $fromTable, $fromColumn, $toTable, $toColumn)) {
                            continue;
                        }

                        $confidence = 'medium';

                        if ($matchRate >= 95) {
                            $confidence = 'high';
                        }

                        if ($matchRate >= 99) {
                            $confidence = 'very_high';
                        }

                        $relations[] = [
                            'from_table' => $fromTable,
                            'from_column' => $fromColumn,
                            'to_table' => $toTable,
                            'to_column' => $toColumn,
                            'confidence' => $confidence,
                            'match_rate' => $matchRate,
                            'type' => 'data_match',
                        ];
                    }
                }
            }
        }

        return $relations;
    }

    /**
     * Получить реальные FOREIGN KEY связи через duckdb_constraints()
     */
    private function getForeignKeyRelations(): array
    {
        $relations = [];

        try {
            $rows = $this->query(
                "SELECT table_name, constraint_text
                 FROM duckdb_constraints()
                 WHERE constraint_type = 'FOREIGN KEY'"
            );
        } catch (\Throwable $e) {
            Log::warning('DuckDB FK detection unavailable: ' . $e->getMessage());
            $rows = collect();
        }

        foreach ($rows as $row) {
            $row = (array) $row;

            $fromTable = $row['table_name'] ?? null;
            $constraintText = $row['constraint_text'] ?? null;

            if (!$fromTable || !$constraintText) {
                continue;
            }

            if (
                preg_match(
                    '/FOREIGN\s+KEY\s*\(\s*([^)]+?)\s*\)\s*REFERENCES\s*(?:"?[a-zA-Z0-9_]+"?\.)?"?([a-zA-Z0-9_]+)"?\s*\(\s*([^)]+?)\s*\)/i',
                    $constraintText,
                    $matches
                )
            ) {
                $fromColumns = $this->splitIdentifierList($matches[1]);
                $toTable = $matches[2];
                $toColumns = $this->splitIdentifierList($matches[3]);

                foreach ($fromColumns as $index => $fromColumn) {
                    $toColumn = $toColumns[$index] ?? ($toColumns[0] ?? null);

                    if ($fromColumn === '' || $toColumn === null || $toColumn === '') {
                        continue;
                    }

                    $relations[] = [
                        'from_table' => $fromTable,
                        'from_column' => $fromColumn,
                        'to_table' => $toTable,
                        'to_column' => $toColumn,
                    ];
                }
            } else {
                Log::warning('DuckDB FK constraint_text не распознан регуляркой', [
                    'table' => $fromTable,
                    'constraint_text' => $constraintText,
                ]);
            }
        }

        try {
            $metaRows = $this->query(
                'SELECT from_table, from_column, to_table, to_column
                 FROM "__datavue_relations"'
            );
        } catch (\Throwable $e) {
            $metaRows = collect();
        }

        foreach ($metaRows as $row) {
            $row = (array) $row;

            $fromTable = $row['from_table'] ?? null;
            $fromColumn = $row['from_column'] ?? null;
            $toTable = $row['to_table'] ?? null;
            $toColumn = $row['to_column'] ?? null;

            if (!$fromTable || !$fromColumn || !$toTable || !$toColumn) {
                continue;
            }

            $relations[] = [
                'from_table' => $fromTable,
                'from_column' => $fromColumn,
                'to_table' => $toTable,
                'to_column' => $toColumn,
            ];
        }

        $unique = [];
        $deduped = [];

        foreach ($relations as $relation) {
            $key = $relation['from_table'] . '|' . $relation['from_column'] . '|' . $relation['to_table'] . '|' . $relation['to_column'];

            if (isset($unique[$key])) {
                continue;
            }

            $unique[$key] = true;
            $deduped[] = $relation;
        }

        return $deduped;
    }

    /**
     * Разбирает содержимое скобок FOREIGN KEY (col1, col2) / REFERENCES
     * t(col1, col2) на массив "голых" имён колонок без кавычек и пробелов.
     */
    private function splitIdentifierList(string $raw): array
    {
        return array_values(array_filter(array_map(
            fn ($part) => trim(trim($part), '"'),
            explode(',', $raw)
        ), fn ($part) => $part !== ''));
    }

    /**
     * Проверяет процент значений, которые существуют в target-таблице
     */
    private function calculateMatchRate(
        string $fromTable,
        string $fromColumn,
        string $toTable,
        string $toColumn
    ): float {

        $fromTableQuoted = $this->quoteIdentifier($fromTable);
        $fromColumnQuoted = $this->quoteIdentifier($fromColumn);

        $toTableQuoted = $this->quoteIdentifier($toTable);
        $toColumnQuoted = $this->quoteIdentifier($toColumn);

        $totalResult = $this->query(
            "SELECT COUNT(DISTINCT {$fromColumnQuoted}) AS total_count
             FROM {$fromTableQuoted}
             WHERE {$fromColumnQuoted} IS NOT NULL"
        );

        $total = (int) ($totalResult[0]->total_count ?? 0);

        if ($total === 0) {
            return 0;
        }

        $matchedResult = $this->query(
            "SELECT COUNT(DISTINCT source.{$fromColumnQuoted}) AS matched_count
             FROM {$fromTableQuoted} AS source
             INNER JOIN {$toTableQuoted} AS target
                 ON source.{$fromColumnQuoted} = target.{$toColumnQuoted}
             WHERE source.{$fromColumnQuoted} IS NOT NULL"
        );

        $matched = (int) ($matchedResult[0]->matched_count ?? 0);

        return round(($matched / $total) * 100, 2);
    }

    /**
     * Проверяет совместимость типов
     */
    private function areTypesCompatible(string $fromType, string $toType): bool
    {
        $fromType = strtolower(preg_replace('/\(.*\)/', '', $fromType));
        $toType = strtolower(preg_replace('/\(.*\)/', '', $toType));

        $numericTypes = [
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint',
            'hugeint', 'decimal', 'numeric', 'float', 'double', 'real',
            'utinyint', 'usmallint', 'uinteger', 'ubigint',
        ];

        $stringTypes = [
            'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'string',
        ];

        if (in_array($fromType, $numericTypes) && in_array($toType, $numericTypes)) {
            return true;
        }

        if (in_array($fromType, $stringTypes) && in_array($toType, $stringTypes)) {
            return true;
        }

        return $fromType === $toType;
    }

    /**
     * Проверяет, существует ли уже такая связь
     */
    private function relationExists(
        array $relations,
        string $fromTable,
        string $fromColumn,
        string $toTable,
        string $toColumn
    ): bool {

        foreach ($relations as $relation) {
            if (
                $relation['from_table'] === $fromTable &&
                $relation['from_column'] === $fromColumn &&
                $relation['to_table'] === $toTable &&
                $relation['to_column'] === $toColumn
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Экранирование идентификатора
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
