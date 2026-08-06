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
                12,
                34,
            ],
            'labels' => [
                'US',
                'DE',
            ],
        ];

        Widget::query()->updateOrCreate(
            ['name' => 'map'],
            [
                'name' => 'map',
                'description' => 'Картограмма мира (choropleth): интенсивность цвета страны отражает величину метрики для этой страны. '
                    . 'ВНИМАНИЕ: виджет пока не подключён на фронтенде (WidgetContainer.vue/Map.vue не читают данные виджета), '
                    . 'поэтому is_ai_selectable=false — ИИ не должен предлагать его, пока фронт не доработан.',
                'scheme' => json_encode(
                    $schemeData,
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                ),
                'scheme_description' => <<<TEXT
series — массив числовых значений, по одному на страну.
Каждое число определяет интенсивность закраски соответствующей страны на карте.

labels — массив двухбуквенных ISO 3166-1 alpha-2 кодов стран (например: US, DE, FR, RU).
Каждый код соответствует значению из массива series по тому же индексу.

Правила:
- количество элементов в labels должно совпадать с количеством элементов в series;
- значения в series должны быть числами (int или float);
- значения в labels должны быть валидными двухбуквенными ISO-кодами стран, а не полными названиями.
TEXT,
                // Пока фронт не подключён — не показывать ИИ при выборе типа виджета.
                'is_ai_selectable' => false,
            ]
        );
    }



}
