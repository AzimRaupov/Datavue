<?php

namespace Database\Seeders\Widgets;

use App\Models\Widget;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MapSeeder extends Seeder
{

    public function run(): void
    {
        $schemeData = [
            'series' => [
                'number',
                'number',
            ],
            'labels' => [
                'string',
                'string',
            ],
        ];

        Widget::query()->updateOrCreate(
            ['name' => 'pie-chart'],
            [
                'name' => 'map',
                'description' => 'КАрта мира можно паказат чтото по странам',
                'scheme' => json_encode(
                    $schemeData,
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                ),
                'scheme_description' => <<<TEXT
series — массив числовых значений для секторов диаграммы.
Каждое число определяет размер соответствующего сектора.

labels — массив названий категорий.
Каждая метка соответствует значению из массива series по индексу.

Правила:
- количество элементов в labels должно совпадать с количеством элементов в series;
- значения в series должны быть числами (int или float);
- значения в labels должны быть строками.
TEXT,
            ]
        );
    }



}
