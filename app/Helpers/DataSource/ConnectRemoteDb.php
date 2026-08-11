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

    /**
     * Имя типа источника из каталога → драйвер PDO.
     *
     * В каталоге тип называется "postgres", а Laravel ждёт "pgsql": без этого
     * перевода проверка подключения к PostgreSQL падала на «Unsupported driver».
     */
    private const DRIVERS = [
        'mysql' => 'mysql',
        'postgres' => 'pgsql',
        'pgsql' => 'pgsql',
        'sqlite' => 'sqlite',
    ];

    private function driver(): string
    {
        $name = strtolower((string) $this->database_name);

        return self::DRIVERS[$name] ?? $name;
    }

    private function defaultPort(): int
    {
        return $this->driver() === 'pgsql' ? 5432 : 3306;
    }

    private function connection()
    {
        $driver = $this->driver();

        // У SQLite нет хоста и учётных данных — только путь к файлу.
        if ($driver === 'sqlite') {
            return $this->remoteConnection([
                'driver' => 'sqlite',
                'database' => $this->database,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ]);
        }

        return $this->remoteConnection([
            'driver' => $driver,
            'host' => $this->host,
            'port' => $this->port ?: $this->defaultPort(),
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
                'message' => $this->explain($e),
            ];
        }
    }

    /**
     * Делает понятной самую частую причину отказа на новом типе источника —
     * отсутствующее расширение PHP.
     */
    private function explain(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'could not find driver')) {
            $extension = match ($this->driver()) {
                'pgsql' => 'php-pgsql',
                'sqlite' => 'php-sqlite3',
                default => 'php-' . $this->driver(),
            };

            return "В PHP не установлен драйвер «{$this->driver()}». "
                . "Установите расширение {$extension} и перезапустите php-fpm.";
        }

        return $message;
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
