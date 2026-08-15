<?php

namespace Database\Seeders;

use App\Models\Filter;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FilersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $filers = [
            [
                'name'=>'date_range',
                'description'=>'Выбор промижутка по дате'
            ],
            [
                'name'=>'date',
                'description'=>'Филтр даты'
            ],
            [
                'name'=>'time_range',
                'description'=>'Выбор промижутков времени'
            ],
            [
                'name'=>'date',
                'description'=>'Филтр даты'
            ],
            [
                'name'=>'select',
                'description'=>'Выборка'
            ],
            [
                'name'=>'search',
                'description'=>'Тектсвове или числовое поле'
            ],
            [
                'name'=>'data_range',
                'description'=>'Выбор промижутка по дате'
            ],
        ];

        foreach ($filers as $filer) {
            Filter::updateOrCreate(
                ['name' => $filer['name']],
                [
                    'description' => $filer['description'],
                ]
            );
        }
    }
}
