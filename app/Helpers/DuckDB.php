<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class DuckDB
{
    public $sql;
    public $pathDb;

    public function __construct($path){
        $this->pathDb = $path;

    }

    public function run($sql){

        $process = Process::run("duckdb {$this->pathDb} -json \"{$sql}\"");

        if ($process->successful()) {

            $duckData = collect(json_decode($process->output(), true));

        } else {
            Log::error("DuckDB CLI Error: " . $process->errorOutput());
            $duckData = collect();
        }
        return $duckData;

    }

}
