<?php

return [

    'duckdb_cli' => env('DUCKDB_CLI'),

    // MySQL, в который разворачиваются загруженные SQL-дампы. По умолчанию — локальный root
    // без пароля (как в XAMPP). На сервере у root вход через auth_socket, поэтому там нужен
    // отдельный пользователь с правами только на базы data_source_* (см. DATASOURCE_MYSQL_* в .env).
    'mysql' => [
        'host' => env('DATASOURCE_MYSQL_HOST', '127.0.0.1'),
        'port' => (int) env('DATASOURCE_MYSQL_PORT', 3306),
        'username' => env('DATASOURCE_MYSQL_USERNAME', 'root'),
        'password' => env('DATASOURCE_MYSQL_PASSWORD'),
    ],

];
