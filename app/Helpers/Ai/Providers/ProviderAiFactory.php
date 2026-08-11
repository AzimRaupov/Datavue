<?php

namespace App\Helpers\Ai\Providers;

use RuntimeException;

/**
 * Подбирает набор промптов под диалект источника данных.
 *
 * Раньше этот match жил внутри конструктора DashboardAi. С появлением второго
 * потребителя (генерация кода выгрузки в файл) копия match'а означала бы, что
 * новый тип источника нужно не забыть добавить в двух местах — а забудется он
 * ровно в том, которое реже открывают.
 */
class ProviderAiFactory
{
    /**
     * @param  \App\Models\DataSource  $dataSource
     */
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
