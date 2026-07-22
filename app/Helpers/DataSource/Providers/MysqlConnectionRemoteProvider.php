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

    public function __construct($host, $port, $username, $password, $database)
    {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->database = $database;
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

    public function showColumns(string $tableName): array
    {
        $rows = $this->query('DESCRIBE ' . $this->quoteIdentifier($tableName));

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
            ->filter(fn ($c) => $c['column_name'])
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

    public function query(string $query, array $bindings = [])
    {
        return $this->connection()->select($query, $bindings);
    }
    public function getSchema()
    {
        $tables=$this->showTables();

        $dbSchema = [];

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

            $dbSchema[$tableName] = $tableColumns;
        }

        return $dbSchema;
    }
    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

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

        return DB::connection('remote_database');
    }
}
