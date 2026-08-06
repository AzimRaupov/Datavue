<?php

namespace App\Helpers\DataSource;

use App\Helpers\DataSource\Concerns\ManagesRemoteConnection;

class ConnectRemoteDb
{
    use ManagesRemoteConnection;

    public $host;
    public $port;
    public $database;
    public $username;
    public $password;
    public $database_name;

    public function __construct(
        $host,
        $port,
        $database,
        $database_name,
        $username,
        $password
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->database_name = $database_name;
        $this->database = $database;
        $this->username = $username;
        $this->password = $password;
        $this->connection();
    }

    private function connection()
    {
        return $this->remoteConnection([
            'driver' => $this->database_name,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
        ]);
    }

    public function check()
    {
        try {

            $this->connection()->getPdo();

            return [
                'success' => true,
                'message' => 'Подключение успешно',
            ];

        } catch (\Throwable $e) {

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function query($query, $bindings = [])
    {
        try {

            return $this->connection()->select(
                $query,
                $bindings
            );

        } catch (\Throwable $e) {

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
