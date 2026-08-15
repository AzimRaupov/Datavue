<?php

namespace App\Helpers\DataSource;

use App\Models\DataSource;
use Illuminate\Support\Facades\Cache;

/**
 * Схема источника: таблицы и их колонки с типами.
 *
 * Нужна в двух местах сразу — панели конструктора, где из колонок собирают
 * запрос, и сборщику SQL, который по этой же схеме проверяет, что колонка
 * существует. Общий кэш обязателен: showColumns() ходит в базу клиента по
 * каждой таблице, а конструктор дёргает схему на каждом открытии виджета.
 */
class SourceSchema
{
    private const TTL_MINUTES = 5;

    /**
     * @return array<int, array{name: string, columns: array<int, array{name: string, type: ?string, kind: string}>}>
     */
    public static function tables(DataSource $dataSource): array
    {
        return Cache::remember(
            self::cacheKey($dataSource->id),
            now()->addMinutes(self::TTL_MINUTES),
            function () use ($dataSource) {
                $router = new ConnectionProviderRouter($dataSource->id);

                $tables = [];

                foreach ($router->showTables() as $table) {
                    $columns = [];

                    foreach ($router->showColumns($table) as $column) {
                        $name = $column['column_name'] ?? null;

                        if (!$name) {
                            continue;
                        }

                        $type = $column['type'] ?? null;

                        $columns[] = [
                            'name' => $name,
                            'type' => $type,
                            // Вид колонки решает, что с ней можно делать:
                            // по числу считают сумму, по дате группируют
                            // по месяцам, по строке — только считают строки.
                            'kind' => self::kindOf($type),
                        ];
                    }

                    $tables[] = ['name' => $table, 'columns' => $columns];
                }

                return $tables;
            }
        );
    }

    /**
     * Плоская карта «таблица => колонка => вид» для быстрых проверок.
     *
     * @return array<string, array<string, string>>
     */
    public static function map(DataSource $dataSource): array
    {
        $map = [];

        foreach (self::tables($dataSource) as $table) {
            foreach ($table['columns'] as $column) {
                $map[$table['name']][$column['name']] = $column['kind'];
            }
        }

        return $map;
    }

    /**
     * Связи между таблицами — чтобы конструктор сам предлагал, по каким
     * колонкам их соединять.
     *
     * Спрашивается отдельно и только по нужным таблицам: провайдер проверяет
     * связи по самим данным, и делать это для всего источника разом дорого.
     *
     * @param array<int, string> $tables
     *
     * @return array<int, array{from_table: string, from_column: string, to_table: string, to_column: string, confidence: ?string}>
     */
    public static function relations(DataSource $dataSource, array $tables): array
    {
        $tables = array_values(array_unique(array_filter($tables)));

        if (count($tables) < 2) {
            return [];
        }

        sort($tables);

        return Cache::remember(
            self::cacheKey($dataSource->id).':relations:'.md5(implode(',', $tables)),
            now()->addMinutes(self::TTL_MINUTES),
            function () use ($dataSource, $tables) {
                $schema = (new ConnectionProviderRouter($dataSource->id))
                    ->getSchema($tables, SchemaOptions::detailed());

                $relations = [];

                foreach ($schema as $table => $definition) {
                    foreach (($definition['relations'] ?? []) as $column => $meta) {
                        $target = $meta['relation'] ?? null;

                        if (!$target || empty($target['table']) || empty($target['column'])) {
                            continue;
                        }

                        $relations[] = [
                            'from_table' => $table,
                            'from_column' => $column,
                            'to_table' => $target['table'],
                            'to_column' => $target['column'],
                            'confidence' => $target['confidence'] ?? null,
                        ];
                    }
                }

                return $relations;
            }
        );
    }

    public static function forget(int $dataSourceId): void
    {
        Cache::forget(self::cacheKey($dataSourceId));
    }

    private static function cacheKey(int $dataSourceId): string
    {
        return "datasource:{$dataSourceId}:schema";
    }

    /**
     * Вид колонки по типу из базы.
     *
     * Типы у провайдеров пишутся по-разному (int(11), bigint, INTEGER,
     * numeric(10,2), timestamp without time zone), поэтому смотрим на
     * подстроку, а не на точное совпадение.
     */
    public static function kindOf(?string $type): string
    {
        $type = strtolower(trim((string) $type));

        if ($type === '') {
            return 'string';
        }

        foreach (['timestamp', 'datetime', 'date', 'time'] as $needle) {
            if (str_contains($type, $needle)) {
                return 'date';
            }
        }

        foreach (['int', 'decimal', 'numeric', 'float', 'double', 'real', 'money', 'number'] as $needle) {
            if (str_contains($type, $needle)) {
                return 'number';
            }
        }

        if (str_contains($type, 'bool')) {
            return 'boolean';
        }

        return 'string';
    }
}
