<?php

namespace Database\Seeders\Widgets;

class TableSeeder extends WidgetFamilySeeder
{
    protected function family(): array
    {
        return [
            'name' => 'table',
            'description' => 'Список сущностей с несколькими атрибутами на каждую: топ-N с подробностями, детальный '
                .'профиль, реестр. Берите, когда важны точные значения и несколько колонок сразу, а не форма распределения. '
                .'Одну метрику по категориям лучше показать через "bar".',

            'scheme' => [
                'headers' => ['string', 'string'],
                'rows' => [
                    ['string', 12],
                ],
            ],

            'scheme_description' => <<<TEXT
headers — массив заголовков колонок (строки).
rows — массив строк, каждая строка это массив значений.

Правила:
- длина каждой строки в rows равна длине headers;
- порядок значений в строке совпадает с порядком headers;
- значения: string, int или float;
- ограничивайте выборку разумным числом строк (обычно топ-10..50) —
  таблица на тысячу строк на дашборде бесполезна;
- сортируйте строки осмысленно (по убыванию ключевой метрики).
TEXT,
        ];
    }

    protected function types(): array
    {
        return [
            [
                'name' => 'plain',
                'title' => 'Обычная',
                'description' => 'Таблица с разделителями строк. Выбор по умолчанию.',
                'options' => ['striped' => false, 'compact' => false],
                'is_default' => true,
            ],
            [
                'name' => 'striped',
                'title' => 'С чередованием строк',
                'description' => 'Чётные строки подсвечены. Берите, когда колонок много и взгляд теряет строку.',
                'options' => ['striped' => true, 'compact' => false],
            ],
            [
                'name' => 'compact',
                'title' => 'Плотная',
                'description' => 'Уменьшенные отступы и шрифт. Берите, когда строк много (20+) и нужно уместить их '
                    .'без прокрутки.',
                'options' => ['striped' => false, 'compact' => true],
            ],
        ];
    }
}
