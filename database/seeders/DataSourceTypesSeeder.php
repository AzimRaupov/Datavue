<?php

namespace Database\Seeders;

use App\Models\DataSourceType;
use Illuminate\Database\Seeder;

/**
 * Справочник провайдеров источников данных.
 *
 * Это единственное место, где описан набор провайдеров: мастер подключения
 * строит из него и список выбора, и форму под каждый вариант. Чтобы добавить
 * новый провайдер, достаточно дописать строку сюда и научить
 * DataSourceCreator его создавать — фронт менять не нужно.
 *
 * kind определяет форму на втором шаге мастера:
 *   file     — загрузка файла;
 *   database — хост, порт, база, логин, пароль;
 *   api      — ссылка на внешний ресурс.
 */
class DataSourceTypesSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'name' => 'mysql',
                'label' => 'MySQL',
                'description' => 'Подключение к серверу MySQL или MariaDB',
                'kind' => 'database',
                'icon' => 'database',
                'default_port' => 3306,
                'position' => 10,
            ],
            [
                'name' => 'postgres',
                'label' => 'PostgreSQL',
                'description' => 'Подключение к серверу PostgreSQL',
                'kind' => 'database',
                'icon' => 'database',
                'default_port' => 5432,
                'position' => 20,
            ],
            [
                'name' => 'duckdb',
                'label' => 'Файл с таблицей',
                'description' => 'CSV или Excel — данные будут загружены в аналитическую базу',
                'kind' => 'file',
                'icon' => 'file-spreadsheet',
                'default_port' => null,
                'position' => 30,
            ],
            [
                'name' => 'sqlite',
                'label' => 'Файл базы SQLite',
                'description' => 'Готовая база .db, .sqlite или .sqlite3',
                'kind' => 'file',
                'icon' => 'file-database',
                'default_port' => null,
                'position' => 40,
            ],
            [
                'name' => 'google_sheets',
                'label' => 'Google Таблицы',
                'description' => 'Таблица по ссылке — данные будут загружены в аналитическую базу',
                'kind' => 'api',
                'icon' => 'table',
                'default_port' => null,
                'position' => 50,
            ],
        ];

        foreach ($types as $position => $type) {
            DataSourceType::updateOrCreate(
                ['name' => $type['name']],
                [
                    'label' => $type['label'],
                    'description' => $type['description'],
                    'kind' => $type['kind'],
                    'icon' => $type['icon'],
                    'default_port' => $type['default_port'],
                    'is_active' => true,
                    'position' => $type['position'],
                ]
            );
        }
    }
}
