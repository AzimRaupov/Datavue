<?php

namespace Database\Seeders\Widgets;

use App\Models\Widget;
use Illuminate\Database\Seeder;

class ScatterPlotSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $schemeData = [
            'series' => [
                [
                    'name' => 'Name',
                    'data' => [11, 12],
                ],
            ],
            'categories' => ['Monday', 'Saturday'],
        ];

        Widget::query()->updateOrCreate(
            ['name' => 'scatter-plot'],
            [
                'name' => 'scatter-plot',
                'description' => 'Диаграмма рассеяния. Отображает значения в виде точек на графике.',
                'scheme' => json_encode(
                    $schemeData,
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                ),
                'scheme_description' => <<<TEXT
Поле 'series' содержит набор данных для построения диаграммы рассеяния.

Для каждого элемента в 'series':
- name — название набора данных (строка);
- data — массив числовых значений (точки на графике).

Поле 'categories' задаёт подписи оси X или категории для точек.
Количество элементов в 'categories' должно соответствовать количеству значений в 'data'.
TEXT,
            ]
        );
    }
}
