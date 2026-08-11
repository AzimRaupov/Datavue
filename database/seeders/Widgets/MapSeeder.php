<?php

namespace Database\Seeders\Widgets;

class MapSeeder extends WidgetFamilySeeder
{
    protected function family(): array
    {
        return [
            'name' => 'map',
            'description' => 'Картограмма мира: интенсивность цвета страны отражает величину метрики. '
                .'Фронт под неё пока не доработан, поэтому виджет исключён из выбора ИИ (is_ai_selectable = false).',

            'is_ai_selectable' => false,

            'scheme' => [
                'series' => [
                    ['code' => 'string', 'value' => 12],
                ],
            ],

            'scheme_description' => <<<TEXT
series — массив стран. Для каждого элемента:
- code — код страны ISO 3166-1 alpha-2 (строка, например "US", "DE");
- value — значение метрики для страны (int или float).

Правила:
- страна, которой нет в данных, просто не подсвечивается;
- коды стран должны быть получены из данных, а не угаданы по названию.
TEXT,
        ];
    }

    protected function types(): array
    {
        return [
            [
                'name' => 'world',
                'title' => 'Карта мира',
                'description' => 'Все страны на одной карте, цвет по величине метрики.',
                'options' => ['scope' => 'world'],
                'is_default' => true,
            ],
        ];
    }
}
