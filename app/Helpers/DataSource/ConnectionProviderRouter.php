<?php

namespace App\Helpers\DataSource;

use App\Helpers\DataSource\Providers\DbConnectionLocalProvider;
use App\Helpers\DataSource\Providers\DbConnectionRemoteProvider;
use App\Helpers\DataSource\Providers\DuckDbConnectionLocalProvider;
use App\Helpers\DataSource\Providers\MysqlConnectionRemoteProvider;
use App\Helpers\DataSource\Providers\PostgresConnectionRemoteProvider;
use App\Helpers\DataSource\Providers\SqliteConnectionLocalProvider;
use App\Models\DataSource;
use App\Models\DataSourceType;
use RuntimeException;

class ConnectionProviderRouter
{
    public $dataSource;
    public $types;
    public $selectedProvider;
    private array $schemaCache = [];

    public function __construct($dataSourceId){

        $this->dataSource = DataSource::query()->with('extracted','type')->find($dataSourceId);

        $this->types = DataSourceType::query()
            ->pluck('id', 'name')
            ->toArray();


        $this->define();

    }
    /**
     * Подбирает провайдер под тип источника.
     *
     * Раньше при незнакомом типе провайдер молча оставался null, и падало это
     * далеко от причины — где-нибудь внутри getSchema с "call on null".
     * Теперь неподдерживаемый тип сразу называет себя.
     */
    public function define(){

        if (!$this->dataSource) {
            throw new RuntimeException('Источник данных не найден');
        }

        $type = $this->dataSource->type->name ?? null;
        $isLocal = $this->dataSource->connection_type === 'local';

        $this->selectedProvider = match (true) {
            // Файловые источники: путь к файлу вместо хоста и порта.
            $isLocal && $type === 'duckdb' => new DuckDbConnectionLocalProvider(
                $this->dataSource->extracted->data_path ?? null
            ),

            $isLocal && $type === 'sqlite' => new SqliteConnectionLocalProvider(
                $this->dataSource->extracted->data_path ?? $this->dataSource->path
            ),

            // Файл SQLite могли подключить и как "внешний" — по пути на диске.
            $type === 'sqlite' => new SqliteConnectionLocalProvider(
                $this->dataSource->path ?? $this->dataSource->database
            ),

            $type === 'mysql' => new MysqlConnectionRemoteProvider(
                $this->dataSource->host,
                $this->dataSource->port,
                $this->dataSource->username,
                $this->dataSource->password,
                $this->dataSource->database,
            ),

            $type === 'postgres' => new PostgresConnectionRemoteProvider(
                $this->dataSource->host,
                $this->dataSource->port,
                $this->dataSource->username,
                $this->dataSource->password,
                $this->dataSource->database,
                $this->dataSource->options['schema'] ?? 'public',
            ),

            default => throw new RuntimeException(
                "Неподдерживаемый тип источника данных: '{$type}'"
                . ($isLocal ? ' (локальный)' : ' (внешний)')
            ),
        };
    }
    public function query($query, $bindings = [])
    {
        return $this->selectedProvider->query($query, $bindings);
    }
    public function showTables()
    {
        return $this->selectedProvider->showTables();

    }
    public function getSchema(array $tables = [], array $options = [])
    {
        // Схема (особенно с count_rows) может запрашиваться много раз подряд для одних
        // и тех же таблиц (по разу на виджет) — кэшируем в рамках жизни роутера.
        $cacheKey = md5(json_encode([$tables, $options]));

        if (array_key_exists($cacheKey, $this->schemaCache)) {
            return $this->schemaCache[$cacheKey];
        }

        return $this->schemaCache[$cacheKey] = $this->selectedProvider->getSchema($tables, $options);
    }
    public function showColumns($tableName)
    {
        return $this->selectedProvider->showColumns($tableName);
    }
    public function check(){
        return $this->selectedProvider->check();
    }
}
