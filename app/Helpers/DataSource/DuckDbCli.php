<?php

namespace App\Helpers\DataSource;

/**
 * Где искать бинарник DuckDB CLI.
 *
 * Тот же приём, что и в PythonRunner: сначала явно заданный путь, иначе —
 * имя команды, которое разрешит PATH. Вынесено в один класс, потому что
 * провайдеров, дёргающих CLI, два, и разъезжаться им незачем.
 */
class DuckDbCli
{
    public static function binary(): string
    {
        $configured = config('datasource.duckdb_cli');

        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }

        return 'duckdb';
    }
}
