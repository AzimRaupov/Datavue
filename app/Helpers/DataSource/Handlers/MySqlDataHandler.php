<?php

namespace App\Helpers\DataSource\Handlers;

use App\Helpers\DataSource\Providers\MysqlConnectionRemoteProvider;
use Throwable;

class MySqlDataHandler
{
    public $host = "127.0.0.1";
    public $port = 3306;
    public $database;
    public $username = "root";
    public $password = null;
    public $sqlPath;
    public $mysqlProvider;

    public function __construct($sqlPath, $database)
    {
        ini_set('memory_limit', '1G');
        set_time_limit(300);
        $this->sqlPath = $sqlPath;
        $this->database = $database;
    }

    public function handle(): array
    {
        return $this->process($this->sqlPath);
    }

    private function process(string $sqlPath): array
    {
        try {
            if (!file_exists($sqlPath)) {
                return [
                    'success' => false,
                    'message' => "Файл дампа не найден по пути: {$sqlPath}",
                ];
            }

            $this->mysqlProvider = new MysqlConnectionRemoteProvider(
                $this->host,
                $this->port,
                $this->username,
                $this->password,
                $this->database
            );

            $this->mysqlProvider->createDatabaseIfNotExists();
            $this->mysqlProvider->import($sqlPath);

            return [
                'success' => true,
                'message' => 'База данных успешно создана и файл импортирован.',
                // сигнализируем наверх, что это фактически remote-подключение
                'connection' => [
                    'type'     => 'mysql',
                    'host'     => $this->host,
                    'port'     => $this->port,
                    'database' => $this->database,
                    'username' => $this->username,
                    'password' => $this->password,
                    'type_database'=> 'mysql',
                ],
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Ошибка при импорте базы данных: ' . $e->getMessage(),
            ];
        }
    }
}
