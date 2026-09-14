<?php

namespace App\Helpers\Ai\Providers;

use RuntimeException;

class ProviderAiFactory
{

    public static function for($dataSource): SqlProviderAi
    {
        $type = $dataSource->type->name ?? null;

        return match ($type) {
            'duckdb' => new DuckDbProviderAi(),
            'mysql' => new MysqlProviderAi(),
            'postgres' => new PostgresProviderAi(),
            'sqlite' => new SqliteProviderAi(),
            default => throw new RuntimeException(
                "Нет генератора кода для источника типа '{$type}'"
            ),
        };
    }
}
