<?php

namespace Database\Seeders\Widgets;

class BarChartSeeder extends WidgetFamilySeeder
{
    protected function family(): array
    {
        return [
            'name' => 'bar',
            'description' => 'Сравнение величины метрики между категориями (товары, регионы, менеджеры, месяцы). '
                .'Рабочая лошадка дашборда: подходит и когда категорий много, и когда нужно сравнить несколько метрик '
                .'по одним и тем же категориям.',

            'scheme' => [
                'series' => [
                    ['name' => 'string', 'data' => [12, 34]],
                ],
                'categories' => ['string', 'string'],
            ],

            'scheme_description' => <<<TEXT
series — массив рядов данных. Для каждого элемента:
- name — название ряда (строка), оно попадёт в легенду;
- data — массив чисел (int или float).

categories — массив подписей категорий (строки).

Правила:
- длина каждого data равна длине categories;
- data[i] относится к categories[i];
- все ряды имеют одинаковую длину data;
- один ряд — сравнение одной метрики по категориям, несколько рядов — сравнение
  нескольких метрик по тем же категориям.
TEXT,
        ];
    }

    protected function types(): array
    {
        return [
            [
                'name' => 'column',
                'title' => 'Вертикальные столбцы',
                'description' => 'Столбцы вверх. Выбор по умолчанию для сравнения до ~12 категорий с короткими названиями.',
                'options' => ['chartType' => 'bar', 'horizontal' => false],
                'is_default' => true,
            ],
            [
                'name' => 'bar',
                'title' => 'Горизонтальные полосы',
                'description' => 'Полосы вправо. Берите, когда названия категорий длинные (наименования товаров, ФИО, '
                    .'адреса) или категорий много — вертикальные подписи в таком случае налезают друг на друга.',
                'options' => ['chartType' => 'bar', 'horizontal' => true],
            ],
            [
                'name' => 'stacked-column',
                'title' => 'Накопительные столбцы',
                'description' => 'Ряды складываются в один столбец. Берите, когда ряды — это части одного целого '
                    .'(выручка по каналам внутри месяца) и важна и сумма, и её состав. Требует минимум двух рядов.',
                'options' => ['chartType' => 'bar', 'horizontal' => false, 'stacked' => true],
            ],
            [
                'name' => 'stacked-bar',
                'title' => 'Накопительные полосы',
                'description' => 'То же накопление, но горизонтально — для длинных названий категорий. Требует минимум двух рядов.',
                'options' => ['chartType' => 'bar', 'horizontal' => true, 'stacked' => true],
            ],
            [
                'name' => 'percent-stacked',
                'title' => 'Доли до 100%',
                'description' => 'Каждый столбец растянут до 100%, показаны доли рядов внутри категории. Берите, когда '
                    .'важна структура, а не абсолютные величины (доля каналов продаж по месяцам). Требует минимум двух рядов.',
                'options' => ['chartType' => 'bar', 'horizontal' => false, 'stacked' => true, 'stackType' => '100%'],
            ],
        ];
    }
}
