<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class DuckDB
{
    public $sql;
    public $pathDb;

    public function __construct($path){
        $this->pathDb = $path;
    }

    public function run($sql){

        $process = Process::run("duckdb {$this->pathDb} -json \"{$sql}\"");

        if ($process->successful()) {

            $duckData = collect(json_decode($process->output(), true));

        } else {
            Log::error("DuckDB CLI Error: " . $process->errorOutput());
            $duckData = collect();
        }
        return $duckData;

    }

    /**
     * Возвращает схему указанных таблиц.
     * Если $tables пустой или не передан — возвращает схему всех таблиц базы.
     *
     * @param array $tables список имён таблиц (пусто = все таблицы)
     * @return array [ 'table_name' => [ 'column_name' => ['type'=>..,'nullable'=>..,'key'=>..,'default'=>..] ] ]
     */
    public function getSchema(array $tables = []): array
    {
        if (empty($tables)) {
            $allTables = $this->run("SHOW TABLES;");

            $tables = $allTables
                ->map(fn($t) => $t['name'] ?? $t['table_name'] ?? null)
                ->filter()
                ->values()
                ->toArray();
        }

        $schema = [];

        foreach ($tables as $tableName) {
            if (!$tableName || !$this->isValidIdentifier($tableName)) {
                Log::warning("DuckDB::getSchema skipped invalid table name", ['table' => $tableName]);
                continue;
            }

            $rawColumns = $this->run("DESCRIBE " . $tableName . ";");

            $tableColumns = [];

            foreach ($rawColumns as $column) {
                $columnName = $column['column_name'] ?? $column['Field'] ?? null;

                if ($columnName) {
                    $tableColumns[$columnName] = [
                        'type' => $column['column_type'] ?? $column['Type'] ?? 'unknown',
                        'nullable' => $column['null'] ?? $column['Null'] ?? 'YES',
                        'key' => $column['key'] ?? $column['Key'] ?? '',
                        'default' => $column['default'] ?? $column['Default'] ?? null,
                    ];
                }
            }

            $schema[$tableName] = $tableColumns;
        }

        return $schema;
    }

    /**
     * Простая защита от SQL/shell-инъекции через имя таблицы,
     * т.к. запрос собирается конкатенацией строк и уходит в shell.
     */
    private function isValidIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name);
    }

}
