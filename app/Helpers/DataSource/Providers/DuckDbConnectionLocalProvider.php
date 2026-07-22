<?php

namespace App\Helpers\DataSource\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Collection;

class DuckDbConnectionLocalProvider
{
    public string $pathDb;

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

        return $rows
            ->map(fn ($row) => $row->name ?? array_values((array) $row)[0] ?? null)
            ->filter()
            ->values()
            ->toArray();
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

    /**
     * Получить информацию о колонках таблицы
     */
    public function showColumns(string $tableName): array
    {
        $rows = $this->query('DESCRIBE ' . $this->quoteIdentifier($tableName));

        return $rows
            ->map(function ($row) {
                $row = (array) $row;

                return [
                    'column_name' => $row['column_name'] ?? $row['Field'] ?? null,
                    'type'        => $row['column_type'] ?? $row['Type'] ?? 'unknown',
                    'nullable'    => $row['null'] ?? $row['Null'] ?? 'YES',
                    'key'         => $row['key'] ?? $row['Key'] ?? '',
                    'default'     => $row['default'] ?? $row['Default'] ?? null,
                    'extra'       => $row['extra'] ?? null,
                ];
            })
            ->filter(fn ($c) => !empty($c['column_name']))
            ->values()
            ->toArray();
    }

    /**
     * Экранирование идентификатора
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Выполнение SQL-запроса через DuckDB CLI
     */
    public function query(string $query, array $bindings = [])
    {
        // Подготовка команды
        $command = [
            'duckdb',
            $this->pathDb,
            '-json',           // вывод в JSON (массив объектов)
            '-c',
            $query,
        ];

        $process = Process::run($command);

        if ($process->successful()) {
            $output = trim($process->output());

            // Если пустой результат (например, CREATE TABLE, INSERT и т.д.)
            if (empty($output)) {
                return collect();
            }

            try {
                $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

                // DuckDB может вернуть массив объектов или просто объект
                if (is_array($decoded) && isset($decoded[0]) && is_array($decoded[0])) {
                    return collect($decoded);
                }

                return collect(is_array($decoded) ? $decoded : [$decoded]);

            } catch (\JsonException $e) {
                Log::error('DuckDB JSON parse error: ' . $e->getMessage(), [
                    'output' => $output,
                    'query'  => $query,
                ]);
                return collect();
            }
        }

        Log::error('DuckDB CLI Error', [
            'query'        => $query,
            'error'        => $process->errorOutput(),
            'exit_code'    => $process->exitCode(),
        ]);

        return collect();
    }

    /**
     * Проверка соединения
     */
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
}
