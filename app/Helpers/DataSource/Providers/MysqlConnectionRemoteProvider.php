<?php

namespace App\Helpers\DataSource\Providers;

use Illuminate\Support\Facades\DB;

class MysqlConnectionRemoteProvider
{
    public string $host;
    public int|string $port;
    public string $username;
    public ?string $password;
    public string $database;

    public function __construct(
        $host,
        $port,
        $username,
        $password,
        $database
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->database = $database;
    }

    /**
     * Создаёт базу данных, если её ещё нет.
     *
     * Использует "сырой" PDO-коннект без указания database,
     * т.к. Laravel-соединение (connection()) требует, чтобы
     * database уже существовала — иначе будет ошибка
     * "Unknown database".
     *
     * После создания базы сбрасываем закешированное
     * Laravel-соединение, чтобы остальные методы
     * (query, showTables, getSchema и т.д.) снова
     * работали как обычно.
     */
    public function createDatabaseIfNotExists(): bool
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;charset=utf8mb4',
                $this->host,
                $this->port
            );

            $pdo = new \PDO(
                $dsn,
                $this->username,
                $this->password,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                ]
            );

            $pdo->exec(
                'CREATE DATABASE IF NOT EXISTS ' .
                $this->quoteIdentifier($this->database) .
                ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );

            $pdo = null;

            // Сбрасываем закешированное соединение Laravel,
            // чтобы следующий connection() подключился уже
            // к существующей базе.
            DB::purge('remote_database');

            return true;
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Ошибка создания базы данных: ' . $e->getMessage()
            );
        }
    }

    public function showTables(): array
    {
        $rows = $this->query('SHOW TABLES');

        return collect($rows)
            ->map(fn ($row) => array_values((array) $row)[0] ?? null)
            ->filter()
            ->values()
            ->toArray();
    }

    public function import(string $sqlPath): bool
    {
        if (!is_file($sqlPath)) {
            throw new \RuntimeException(
                "SQL файл не найден: {$sqlPath}"
            );
        }

        $command = sprintf(
            'mysql --host=%s --port=%s --user=%s %s < %s',
            escapeshellarg($this->host),
            escapeshellarg((string) $this->port),
            escapeshellarg($this->username),
            escapeshellarg($this->database),
            escapeshellarg($sqlPath)
        );

        if ($this->password !== null && $this->password !== '') {
            $command = sprintf(
                'MYSQL_PWD=%s %s',
                escapeshellarg($this->password),
                $command
            );
        }

        exec(
            $command . ' 2>&1',
            $output,
            $exitCode
        );

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "Ошибка импорта SQL:\n" .
                implode("\n", $output)
            );
        }

        return true;
    }
    public function showColumns(string $tableName): array
    {
        $rows = $this->query(
            'DESCRIBE ' . $this->quoteIdentifier($tableName)
        );

        return collect($rows)
            ->map(function ($row) {
                $row = (array) $row;

                return [
                    'column_name' => $row['Field'] ?? null,
                    'type' => $row['Type'] ?? 'unknown',
                    'nullable' => $row['Null'] ?? 'YES',
                    'key' => $row['Key'] ?? '',
                    'default' => $row['Default'] ?? null,
                ];
            })
            ->filter(fn ($column) => $column['column_name'])
            ->values()
            ->toArray();
    }

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
                'message' => $e->getMessage(),
            ];
        }
    }

    public function query(
        string $query,
        array $bindings = []
    ) {
        return $this->connection()->select(
            $query,
            $bindings
        );
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
        }

        /*
        |--------------------------------------------------------------------------
        | Формируем результат
        |--------------------------------------------------------------------------
        */

        foreach ($tables as $tableName) {

            $tableSchema = [];

            /*
            |--------------------------------------------------------------------------
            | count_rows
            |--------------------------------------------------------------------------
            */

            if ($includeCountRows) {

                $tableSchema['count_rows'] =
                    $this->getTableCount($tableName);
            }

            /*
            |--------------------------------------------------------------------------
            | columns
            |--------------------------------------------------------------------------
            */

            if ($includeColumns) {

                $tableColumns = $allColumns[$tableName];

                /*
                |--------------------------------------------------------------------------
                | Удаляем из columns поля, которые участвуют в relations
                |--------------------------------------------------------------------------
                */

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

            /*
            |--------------------------------------------------------------------------
            | relations
            |--------------------------------------------------------------------------
            */

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

                    /*
                    |--------------------------------------------------------------------------
                    | Данные колонки
                    |--------------------------------------------------------------------------
                    */

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

                    /*
                    |--------------------------------------------------------------------------
                    | Данные связи
                    |--------------------------------------------------------------------------
                    */

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
    /**
     * Получить количество записей таблицы
     */
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
     * Определение связей между таблицами
     *
     * Использует:
     *
     * 1. Реальный FOREIGN KEY
     * 2. Совпадение имени колонок
     * 3. Проверку реальных значений через JOIN
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

                /*
                | Нельзя сравнивать таблицу с самой собой
                */

                if ($fromTable === $toTable) {
                    continue;
                }

                /*
                | Если таблица уже является справочником,
                | обычно она является target-таблицей.
                */

                foreach (
                    $schema[$fromTable]['columns']
                    as $fromColumn => $fromColumnData
                ) {

                    foreach (
                        $schema[$toTable]['columns']
                        as $toColumn => $toColumnData
                    ) {

                        /*
                        |--------------------------------------------------------------------------
                        | Не сравниваем колонки с несовместимыми типами
                        |--------------------------------------------------------------------------
                        */

                        if (
                            !$this->areTypesCompatible(
                                $fromColumnData['type'],
                                $toColumnData['type']
                            )
                        ) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Уникальные колонки target предпочтительнее
                        |--------------------------------------------------------------------------
                        */

                        $isTargetColumn = in_array(
                            $toColumnData['key'],
                            ['PRI', 'UNI']
                        );

                        /*
                        | Если target не является уникальным,
                        | связь может быть ненадёжной.
                        */

                        if (!$isTargetColumn) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Проверяем реальные значения
                        |--------------------------------------------------------------------------
                        */

                        $matchRate = $this->calculateMatchRate(
                            $fromTable,
                            $fromColumn,
                            $toTable,
                            $toColumn
                        );

                        /*
                        |--------------------------------------------------------------------------
                        | Минимальный процент совпадения
                        |--------------------------------------------------------------------------
                        */

                        if ($matchRate < 80) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Не дублируем FOREIGN KEY
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $this->relationExists(
                                $relations,
                                $fromTable,
                                $fromColumn,
                                $toTable,
                                $toColumn
                            )
                        ) {
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
     * Получить реальные FOREIGN KEY связи
     */
    private function getForeignKeyRelations(): array
    {
        $rows = $this->query(
            "
            SELECT
                TABLE_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE
                TABLE_SCHEMA = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL
                AND REFERENCED_COLUMN_NAME IS NOT NULL
            ",
            [$this->database]
        );

        return collect($rows)
            ->map(function ($row) {

                $row = (array) $row;

                return [
                    'from_table' =>
                        $row['TABLE_NAME'] ?? null,

                    'from_column' =>
                        $row['COLUMN_NAME'] ?? null,

                    'to_table' =>
                        $row['REFERENCED_TABLE_NAME'] ?? null,

                    'to_column' =>
                        $row['REFERENCED_COLUMN_NAME'] ?? null,
                ];
            })
            ->filter(function ($relation) {

                return
                    $relation['from_table'] &&
                    $relation['from_column'] &&
                    $relation['to_table'] &&
                    $relation['to_column'];
            })
            ->values()
            ->toArray();
    }

    /**
     * Проверяет процент значений,
     * которые существуют в target-таблице
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

        /*
        |--------------------------------------------------------------------------
        | Общее количество уникальных значений source
        |--------------------------------------------------------------------------
        */

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

        /*
        |--------------------------------------------------------------------------
        | Количество совпавших значений
        |--------------------------------------------------------------------------
        */

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

    /**
     * Проверяет совместимость типов
     */
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
     * Экранирует имя таблицы или колонки
     */
    private function quoteIdentifier(
        string $identifier
    ): string {

        return '`' .
            str_replace(
                '`',
                '``',
                $identifier
            ) .
            '`';
    }

    /**
     * Подключение к удалённой MySQL базе
     */
    private function connection()
    {
        config([
            'database.connections.remote_database' => [
                'driver' => 'mysql',
                'host' => $this->host,
                'port' => $this->port,
                'database' => $this->database,
                'username' => $this->username,
                'password' => $this->password,
            ],
        ]);

        return DB::connection(
            'remote_database'
        );
    }
}
