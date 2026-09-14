<?php

namespace App\Helpers\DataSource\Providers;

use App\Helpers\DataSource\Concerns\ManagesRemoteConnection;

abstract class AbstractSqlConnectionProvider
{
    use ManagesRemoteConnection;

    abstract protected function connectionConfig(): array;

    abstract public function showTables(): array;

    abstract public function showColumns(string $tableName): array;

    abstract protected function getForeignKeyRelations(): array;

    abstract protected function quoteIdentifier(string $identifier): string;

    public function check(): array
    {
        try {
            $this->connection()->getPdo();

            return [
                'success' => true,
                'message' => 'Подключение успешно',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $this->explainConnectionError($e),
            ];
        }
    }

    public function query(string $query, array $bindings = [])
    {
        return $this->connection()->select($query, $bindings);
    }

    protected function connection()
    {
        return $this->remoteConnection($this->connectionConfig());
    }

    protected function explainConnectionError(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'could not find driver')) {
            $driver = $this->connectionConfig()['driver'] ?? '?';

            return "В PHP не установлен драйвер для «{$driver}». "
                . "Установите соответствующее расширение (например php-pgsql для postgres "
                . "или php-sqlite3 для sqlite) и перезапустите php-fpm.";
        }

        return $message;
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
        $includeSampleValues = in_array('sample_values', $options);

        $allColumns = [];

        foreach ($tables as $tableName) {

            $columns = $this->showColumns($tableName);

            $tableColumns = [];

            foreach ($columns as $column) {

                $columnName = $column['column_name'] ?? null;

                if (!$columnName) {
                    continue;
                }

                $type = $column['type'] ?? 'unknown';
                $key = $column['key'] ?? '';

                $columnMeta = [
                    'type' => $type,
                    'nullable' => $column['nullable'] ?? 'YES',
                    'key' => $key,
                    'default' => $column['default'] ?? null,
                ];

                if ($includeSampleValues && $this->isEnumerableType($type) && !in_array($key, ['PRI', 'UNI'], true)) {
                    $samples = $this->fetchSampleValues($tableName, $columnName);

                    if ($samples !== null) {
                        $columnMeta['sample_values'] = $samples;
                    }
                }

                $tableColumns[$columnName] = $columnMeta;
            }

            $allColumns[$tableName] = $tableColumns;
        }

        $relations = [];

        if ($includeRelations) {

            $relationSchema = [];

            foreach ($allColumns as $tableName => $columns) {
                $relationSchema[$tableName] = [
                    'columns' => $columns,
                ];
            }

            $relations = $this->detectRelations($relationSchema);
        }

        foreach ($tables as $tableName) {

            $tableSchema = [];

            if ($includeCountRows) {

                $tableSchema['count_rows'] =
                    $this->getTableCount($tableName);
            }

            if ($includeColumns) {

                $tableColumns = $allColumns[$tableName];

                if ($includeRelations) {

                    foreach ($relations as $relation) {

                        if (
                            $relation['from_table'] === $tableName
                        ) {

                            $fromColumn =
                                $relation['from_column'];

                            unset(
                                $tableColumns[$fromColumn]
                            );
                        }
                    }
                }

                $tableSchema['columns'] =
                    $tableColumns;
            }

            if ($includeRelations) {

                foreach ($relations as $relation) {

                    $fromTable =
                        $relation['from_table'];

                    $fromColumn =
                        $relation['from_column'];

                    if ($fromTable !== $tableName) {
                        continue;
                    }

                    if (
                        !isset(
                            $allColumns[$fromTable][$fromColumn]
                        )
                    ) {
                        continue;
                    }

                    $columnData =
                        $allColumns[$fromTable][$fromColumn];

                    $relationColumn = [];

                    foreach (
                        $options['relations']['column'] ?? []
                        as $field
                    ) {

                        if (
                            array_key_exists(
                                $field,
                                $columnData
                            )
                        ) {
                            $relationColumn[$field] =
                                $columnData[$field];
                        }
                    }

                    $relationData = [
                        'table' =>
                            $relation['to_table'],

                        'column' =>
                            $relation['to_column'],

                        'confidence' =>
                            $relation['confidence'],

                        'match_rate' =>
                            $relation['match_rate'],
                    ];

                    $filteredRelation = [];

                    foreach (
                        $options['relations']['relation'] ?? []
                        as $field
                    ) {

                        if (
                            array_key_exists(
                                $field,
                                $relationData
                            )
                        ) {
                            $filteredRelation[$field] =
                                $relationData[$field];
                        }
                    }

                    $relationColumn['relation'] =
                        $filteredRelation;

                    $tableSchema['relations'][$fromColumn] =
                        $relationColumn;
                }
            }

            $schema[$tableName] =
                $tableSchema;
        }

        return $schema;
    }

    private function isEnumerableType(string $type): bool
    {
        $normalized = strtolower(preg_replace('/\(.*\)/', '', $type) ?? $type);

        return in_array($normalized, [
            'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'string', 'enum', 'bpchar',
        ], true);
    }

    private function fetchSampleValues(string $tableName, string $columnName, int $limit = 20): ?array
    {
        $column = $this->quoteIdentifier($columnName);
        $table = $this->quoteIdentifier($tableName);

        $query = "SELECT DISTINCT {$column} AS sample_value FROM {$table} WHERE {$column} IS NOT NULL LIMIT ".($limit + 1);

        try {
            $rows = $this->query($query);
        } catch (\Throwable $e) {
            return null;
        }

        if (count($rows) > $limit) {
            return null;
        }

        $values = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $value = $row['sample_value'] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $values[] = (string) $value;
        }

        return $values === [] ? null : $values;
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

    private function detectRelations(array $schema): array
    {
        $relations = [];

        $foreignKeys = $this->getForeignKeyRelations();

        foreach ($foreignKeys as $foreignKey) {

            if (
                !isset($schema[$foreignKey['from_table']]) ||
                !isset($schema[$foreignKey['to_table']])
            ) {
                continue;
            }

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

        $tables = array_keys($schema);

        foreach ($tables as $fromTable) {

            foreach ($tables as $toTable) {

                if ($fromTable === $toTable) {
                    continue;
                }

                foreach (
                    $schema[$fromTable]['columns']
                    as $fromColumn => $fromColumnData
                ) {

                    if (
                        $this->columnHasRelation(
                            $relations,
                            $fromTable,
                            $fromColumn
                        )
                    ) {
                        continue;
                    }

                    if (($fromColumnData['key'] ?? '') === 'PRI') {
                        continue;
                    }

                    foreach (
                        $schema[$toTable]['columns']
                        as $toColumn => $toColumnData
                    ) {

                        if (
                            !$this->areTypesCompatible(
                                $fromColumnData['type'],
                                $toColumnData['type']
                            )
                        ) {
                            continue;
                        }

                        $isTargetColumn = in_array(
                            $toColumnData['key'],
                            ['PRI', 'UNI']
                        );

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

                        continue 2;
                    }
                }
            }
        }

        return $relations;
    }

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
            "
            SELECT COUNT(DISTINCT {$fromColumnQuoted})
            AS total_count

            FROM {$fromTableQuoted}

            WHERE {$fromColumnQuoted} IS NOT NULL
            "
        );

        $total = (int) (
            $totalResult[0]->total_count ?? 0
        );

        if ($total === 0) {
            return 0;
        }

        $matchedResult = $this->query(
            "
            SELECT COUNT(DISTINCT source.{$fromColumnQuoted})
            AS matched_count

            FROM {$fromTableQuoted} AS source

            INNER JOIN {$toTableQuoted} AS target
                ON source.{$fromColumnQuoted}
                = target.{$toColumnQuoted}

            WHERE source.{$fromColumnQuoted} IS NOT NULL
            "
        );

        $matched = (int) (
            $matchedResult[0]->matched_count ?? 0
        );

        return round(
            ($matched / $total) * 100,
            2
        );
    }

    private function areTypesCompatible(
        string $fromType,
        string $toType
    ): bool {

        $fromType = strtolower(
            preg_replace(
                '/\(.*\)/',
                '',
                $fromType
            )
        );

        $toType = strtolower(
            preg_replace(
                '/\(.*\)/',
                '',
                $toType
            )
        );

        $numericTypes = [
            'tinyint',
            'smallint',
            'mediumint',
            'int',
            'integer',
            'bigint',
            'decimal',
            'numeric',
            'float',
            'double',
        ];

        $stringTypes = [
            'char',
            'varchar',
            'text',
            'tinytext',
            'mediumtext',
            'longtext',
        ];

        if (
            in_array($fromType, $numericTypes) &&
            in_array($toType, $numericTypes)
        ) {
            return true;
        }

        if (
            in_array($fromType, $stringTypes) &&
            in_array($toType, $stringTypes)
        ) {
            return true;
        }

        return $fromType === $toType;
    }

    private function columnHasRelation(
        array $relations,
        string $fromTable,
        string $fromColumn
    ): bool {

        foreach ($relations as $relation) {

            if (
                $relation['from_table'] === $fromTable &&
                $relation['from_column'] === $fromColumn
            ) {
                return true;
            }
        }

        return false;
    }
}
