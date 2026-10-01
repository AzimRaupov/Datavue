<?php

namespace App\Helpers\DataSource;

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
