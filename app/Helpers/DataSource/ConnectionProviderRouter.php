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
    public function getSchema()
    {
        return $this->selectedProvider->getSchema();
    }
    public function showColumns($tableName)
    {
        return $this->selectedProvider->showColumns($tableName);
    }
    public function check(){
        return $this->selectedProvider->check();
    }
}
