<?php

namespace Database\Seeders\Widgets;

use App\Models\Widget;
use Illuminate\Database\Seeder;

class MiniCountersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $schemeData = [
            'counters' => [
                [
                    'name' => 'max',
                    'value' => 12,
                    'prefix' => '',
                    'suffix' => '',
                ],
            ],
        ];

        Widget::query()->updateOrCreate(
            ['name' => 'mini-counters'],
            [
                'name' => 'mini-counters',
                'description' => 'Предназначен для отображения набора мини-счётчиков (статистики), где для каждого элемента задаются название, значение, префикс и суффикс (необязательно).',
                'scheme' => json_encode(
                    $schemeData,
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                ),
                'scheme_description' => "Поле 'counters' содержит массив счётчиков. Для каждого элемента:
- name — название счётчика (строка);
- value — значение счётчика (int или float);
- prefix — префикс, отображаемый перед значением (необязательно);
- suffix — суффикс, отображаемый после значения (необязательно).",
            ]
        );
    }
}
