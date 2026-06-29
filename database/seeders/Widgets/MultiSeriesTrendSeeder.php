<?php

namespace Database\Seeders\Widgets;

use App\Models\Widget;
use Illuminate\Database\Seeder;

class MultiSeriesTrendSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $schemeData = [
            'series' => [
                [
                    'name' => 'string',
                    'data' => [
                        12,
                    ],
                ],
            ],
            'labels' => [
                'string',
            ],
        ];

        Widget::query()->updateOrCreate(
            ['name' => 'multi-series-trend'],
            [
                'name' => 'multi-series-trend',
                'description' => 'Интерактивный линейный график для сравнения нескольких показателей в разрезе времени.',
                'scheme' => json_encode(
                    $schemeData,
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                ),
                'scheme_description' => <<<TEXT
Поле 'series' содержит массив рядов данных для отображения на графике.

Для каждого элемента в 'series':
- name — название ряда данных (строка);
- data — массив числовых значений (int или float).

Поле 'labels' содержит массив подписей для оси X.
Количество элементов в 'labels' должно соответствовать количеству значений в каждом массиве 'data'.
TEXT,
            ]
        );
    }
}
