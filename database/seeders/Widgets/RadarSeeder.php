<?php

namespace Database\Seeders\Widgets;

use App\Models\Widget;
use Illuminate\Database\Seeder;

class RadarSeeder extends Seeder
{
    public function run(): void
    {
        $schemeData = [
            'series' => [
                [
                    'name' => 'string',
                    'data' => [
                        80,
                        50,
                    ],
                ],

            ],
            'categories' => [
                'string',
                'string',

            ],
        ];

        Widget::query()->updateOrCreate(
            ['name' => 'radar'],
            [
                'name' => 'radar',

                'description' => 'Радарная диаграмма для сравнения нескольких рядов данных по категориям.',

                'scheme' => json_encode(
                    $schemeData,
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                ),

                'scheme_description' => <<<TEXT
Поле 'series' содержит массив рядов данных для отображения на радарной диаграмме.

Каждый элемент массива 'series' представляет отдельный ряд данных:

- name — название ряда данных (строка);
- data — массив числовых значений (int или float).

На одной радарной диаграмме может отображаться несколько рядов данных.

Поле 'categories' содержит массив названий категорий.

Количество элементов в 'categories' должно соответствовать количеству значений в каждом массиве 'data'.

Например, если 'categories' содержит 6 элементов, каждый ряд в 'series' должен содержать 6 числовых значений.

Все ряды в 'series' должны иметь одинаковое количество значений.
TEXT,
            ]
        );
    }
}
