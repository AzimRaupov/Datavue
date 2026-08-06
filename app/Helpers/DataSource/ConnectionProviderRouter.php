<?php

namespace App\Helpers\DataSource;

use App\Helpers\DataSource\Providers\DbConnectionLocalProvider;
use App\Helpers\DataSource\Providers\DbConnectionRemoteProvider;
use App\Helpers\DataSource\Providers\DuckDbConnectionLocalProvider;
use App\Helpers\DataSource\Providers\MysqlConnectionRemoteProvider;
use App\Models\DataSource;
use App\Models\DataSourceType;

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
    public function define(){

        if($this->dataSource->connection_type == "local"){
            if($this->dataSource->type->name == "duckdb") {
                $this->selectedProvider = new DuckDbConnectionLocalProvider($this->dataSource->extracted->data_path);
            }
        }
        else{
            if($this->dataSource->type->name == "mysql") {
                $this->selectedProvider = new MysqlConnectionRemoteProvider(
                    $this->dataSource->host,
                    $this->dataSource->port,
                    $this->dataSource->username,
                    $this->dataSource->password,
                    $this->dataSource->database,
                    );
            }
        }


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
