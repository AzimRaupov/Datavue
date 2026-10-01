<?php

namespace Database\Seeders;

use App\Models\WidgetFilter;
use Illuminate\Database\Seeder;

class WidgetFilterSeeder extends Seeder
{
    public function run(): void
    {
        $filters = [
            [
                'key' => 'paginate',
                'label' => 'Постраничный вывод',
                'description' => 'Листание результата вместо обрезания лишних строк.',
                'applies_as' => 'wrapper',
                'params' => ['page', 'per_page'],

                'required_for' => ['table'],
                'requires_date_column' => false,
                'position' => 10,
            ],
            [
                'key' => 'search',
                'label' => 'Поиск',
                'description' => 'Поиск по видимым колонкам результата.',
                'applies_as' => 'wrapper',
                'params' => ['search'],
                'required_for' => ['table'],
                'requires_date_column' => false,
                'position' => 20,
            ],
            [
                'key' => 'date_range',
                'label' => 'Период',
                'description' => 'Ограничение выборки диапазоном дат.',
                'applies_as' => 'query',
                'params' => ['date_from', 'date_to'],
                'required_for' => [],

                'requires_date_column' => true,
                'position' => 30,
            ],
            [
                'key' => 'day',
                'label' => 'Один день',
                'description' => 'Показать данные за конкретную дату.',
                'applies_as' => 'query',
                'params' => ['day'],
                'required_for' => [],
                'requires_date_column' => true,
                'position' => 40,
            ],
            [
                'key' => 'limit',
                'label' => 'Сколько показывать',
                'description' => 'Число элементов на графике: топ-5, топ-10, топ-20.',
                'applies_as' => 'wrapper',
                'params' => ['limit'],
                'required_for' => [],
                'requires_date_column' => false,
                'position' => 50,
            ],
        ];

        foreach ($filters as $filter) {
            WidgetFilter::updateOrCreate(
                ['key' => $filter['key']],
                $filter + ['is_active' => true]
            );
        }
    }
}
