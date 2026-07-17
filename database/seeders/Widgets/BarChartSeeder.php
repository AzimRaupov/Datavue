<?php

namespace Database\Seeders\Widgets;

use App\Models\Widget;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BarChartSeeder extends Seeder
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
            'categories' => [
                'string',
            ],
        ];

        Widget::query()->updateOrCreate(
            ['name' => 'bar'],
            [
                'name' => 'bar',
                'description' => 'Столбчатая диаграмма для отображения и сравнения значений по категориям.',
                'scheme' => json_encode(
                    $schemeData,
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                ),
                'scheme_description' => <<<TEXT
Поле 'series' содержит массив рядов данных для отображения на графике.

Для каждого элемента в 'series':
- name — название ряда данных (строка);
- data — массив числовых значений (int или float).

Поле 'categories' содержит массив подписей для оси X.
Количество элементов в 'categories' должно соответствовать количеству значений в каждом массиве 'data'.
TEXT,
            ]
        );
    }

}
