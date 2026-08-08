<?php

namespace App\Helpers\DataSource\Providers;

use App\Helpers\DataSource\Concerns\ManagesRemoteConnection;

/**
 * Общая часть SQL-провайдеров источников данных.
 *
 * Всё, что не зависит от диалекта, живёт здесь: сборка схемы, определение связей
 * по внешним ключам и по совпадению значений, оценка достоверности связи.
 * Раньше это существовало единственной копией внутри mysql-провайдера, и любой
 * новый диалект означал бы копирование девятисот строк.
 *
 * Наследнику остаётся диалектная часть: как подключиться, как процитировать
 * идентификатор, как спросить список таблиц, колонки и внешние ключи.
 */
abstract class AbstractSqlConnectionProvider
{
    use ManagesRemoteConnection;

    /**
     * Конфиг соединения Laravel: driver, host/port/database либо путь к файлу.
     */
    abstract protected function connectionConfig(): array;

    /**
     * Имена таблиц источника.
     *
     * @return array<int, string>
     */
    abstract public function showTables(): array;

    /**
     * Колонки таблицы в едином виде, независимо от диалекта:
     * column_name, type, nullable ("YES"/"NO"), key ("PRI"/"UNI"/""), default.
     *
     * @return array<int, array<string, mixed>>
     */
    abstract public function showColumns(string $tableName): array;

    /**
     * Реальные внешние ключи источника в едином виде:
     * from_table, from_column, to_table, to_column.
     *
     * @return array<int, array<string, string>>
     */
    abstract protected function getForeignKeyRelations(): array;

    /**
     * Цитирование идентификатора по правилам диалекта.
     */
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

    /**
     * Делает ошибку подключения понятной.
     *
     * Самая частая причина на новом диалекте — не установленный PDO-драйвер,
     * и штатное "could not find driver" ни о чём не говорит тому, кто просто
     * подключает базу через интерфейс.
     */
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

            /*
            | information_schema отдаёт связи по всей схеме, но считать
            | match_rate можно только для таблиц, которые реально попали
            | в анализ — иначе запрос упадёт на несуществующей таблице.
            */

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

                    /*
                    |--------------------------------------------------------------------------
                    | У колонки уже есть связь
                    |--------------------------------------------------------------------------
                    |
                    | В схему уходит одна связь на колонку (ключ — имя колонки),
                    | поэтому каждая следующая найденная связь просто затирала
                    | предыдущую. Настоящий FOREIGN KEY добавляется до эвристики,
                    | значит он и должен побеждать.
                    */

                    if (
                        $this->columnHasRelation(
                            $relations,
                            $fromTable,
                            $fromColumn
                        )
                    ) {
                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Собственный PRIMARY KEY — не внешний ключ
                    |--------------------------------------------------------------------------
                    |
                    | Значения id почти всегда совпадают с id любой другой
                    | таблицы, и эвристика выдавала ложную связь. Реальная
                    | связь по PK (1:1) уже пришла бы из FOREIGN KEY.
                    */

                    if (($fromColumnData['key'] ?? '') === 'PRI') {
                        continue;
                    }

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

                        /*
                        | Одна связь на колонку — дальше по этой колонке
                        | искать нечего.
                        */

                        continue 2;
                    }
                }
            }
        }

        return $relations;
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
     * Проверяет, найдена ли уже связь для этой колонки
     */
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
