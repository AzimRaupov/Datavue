<?php

namespace App\Helpers\DataSource\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class DbConnectionLocalProvider
{
    public string $pathDb;

    public function __construct(string $pathDb)
    {
        $this->pathDb = $pathDb;
    }

    public function query(string $query, array $bindings = [])
    {
        $process = Process::run([
            'duckdb',
            $this->pathDb,
            '-json',
            '-c',
            $query,
        ]);

        if ($process->successful()) {
            return collect(
                json_decode($process->output(), true)
            );
        }

        Log::error(
            'DuckDB CLI Error: ' . $process->errorOutput()
        );

        return collect();
    }

    public function check(): array
    {
        try {
            $process = Process::run([
                'duckdb',
                $this->pathDb,
                '-c',
                'SELECT 1',
            ]);

            if ($process->successful()) {
                return [
                    'success' => true,
                    'message' => 'Подключение успешно',
                ];
            }

            return [
                'success' => false,
                'message' => $process->errorOutput(),
            ];

        } catch (\Throwable $e) {

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
