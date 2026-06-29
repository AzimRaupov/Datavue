<?php

namespace Database\Seeders\Widgets;

use App\Models\Widget;
use Illuminate\Database\Seeder;

class TableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $schemeData = [
            'headers' => [
                'string',
            ],
            'rows' => [
                [
                    'string|int|float',
                ],
            ],
        ];

        Widget::query()->updateOrCreate(
            ['name' => 'table'],
            [
                'name' => 'table',
                'description' => 'Таблица с заголовками и строками.',
                'scheme' => json_encode(
                    $schemeData,
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                ),
                'scheme_description' => <<<TEXT
headers — массив заголовков таблицы (строки).
Каждый элемент представляет название колонки.

rows — массив строк таблицы.
Каждая строка представляет собой массив значений.

Правила:
- количество элементов в каждой строке rows должно совпадать с количеством headers;
- порядок значений в rows соответствует порядку headers;
- значения могут быть типов: string, int, float.
TEXT,
            ]
        );
    }
}
