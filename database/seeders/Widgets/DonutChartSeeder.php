<?php

namespace Database\Seeders\Widgets;

use App\Models\Widget;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DonutChartSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
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
            ['name' => 'donut-chart'],
            [
                'name' => 'donut-chart',
                'description' => 'Кольцевая (donut) диаграмма. Каждый сектор представляет категорию и её долю от общего значения.',
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
