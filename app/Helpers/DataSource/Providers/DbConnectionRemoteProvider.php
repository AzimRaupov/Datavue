<?php

namespace App\Helpers\DataSource\Providers;

use App\Helpers\DataSource\Concerns\ManagesRemoteConnection;

class DbConnectionRemoteProvider
{
    use ManagesRemoteConnection;

    public string $driver;
    public string $host;
    public int|string $port;
    public string $username;
    public ?string $password;
    public string $database;

    public function __construct(
        $host,
        $port,
        $username,
        $password,
        $database,
        $driver
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->database = $database;
        $this->driver = $driver;
    }



    public function check(): array
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

    public function query(string $query, array $bindings = [])
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

    private function connection()
    {
        return $this->remoteConnection([
            'driver' => $this->driver,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
        ]);
    }
}
