<?php

namespace App\Helpers\DataSource\Providers;

use Illuminate\Support\Facades\DB;

/**
 * Источник данных MySQL.
 *
 * Сборка схемы и определение связей живут в AbstractSqlConnectionProvider —
 * здесь остаётся только то, чем MySQL отличается от других диалектов.
 */
class MysqlConnectionRemoteProvider extends AbstractSqlConnectionProvider
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

    protected function connectionConfig(): array
    {
        return [
            'driver' => 'mysql',
            'host' => $this->host,
            'port' => $this->port ?: 3306,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
        ];
    }

    protected function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
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

    protected function getForeignKeyRelations(): array
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
                    'from_table' => $row['TABLE_NAME'] ?? null,
                    'from_column' => $row['COLUMN_NAME'] ?? null,
                    'to_table' => $row['REFERENCED_TABLE_NAME'] ?? null,
                    'to_column' => $row['REFERENCED_COLUMN_NAME'] ?? null,
                ];
            })
            ->filter(fn ($relation) => $relation['from_table']
                && $relation['from_column']
                && $relation['to_table']
                && $relation['to_column'])
            ->values()
            ->toArray();
    }

    /**
     * Создаёт базу данных, если её ещё нет.
     *
     * Использует "сырой" PDO-коннект без указания database, т.к. Laravel-соединение
     * требует, чтобы база уже существовала — иначе будет "Unknown database".
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
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );

            $pdo->exec(
                'CREATE DATABASE IF NOT EXISTS ' .
                $this->quoteIdentifier($this->database) .
                ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );

            $pdo = null;

            // Сбрасываем закешированное соединение, чтобы следующий connection()
            // подключился уже к существующей базе.
            DB::purge('remote_database');

            return true;
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Ошибка создания базы данных: ' . $e->getMessage()
            );
        }
    }

    public function import(string $sqlPath): bool
    {
        if (!is_file($sqlPath)) {
            throw new \RuntimeException("SQL файл не найден: {$sqlPath}");
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

        exec($command . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "Ошибка импорта SQL:\n" . implode("\n", $output)
            );
        }

        return true;
    }
}
