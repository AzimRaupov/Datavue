<?php

namespace Database\Seeders;

use App\Models\Task;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TaskSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tasks = [
                [
                    'name'=>'detect_schema_dashboard',
                    'description'=>'Определение схемы дашборда',
                ],
                [
                    'name'=>'define_task',
                    'description'=>'define_task'
                ],
                [
                  'name'=>'generate_widgets_dashboard',
                  'description'=>'Генератсия виджетов.',
                ],
                [
                    'name' => 'generate_dashboard',
                    "description" => "Используется, когда пользователь хочет визуализировать,
проанализировать или сгруппировать данные: построить график, диаграмму, отчёт,
сводную таблицу, сравнить показатели, посчитать метрики, увидеть тренды.
Подходит для любых запросов на анализ/визуализацию загруженных данных,
даже если пользователь не использует слово 'дашборд' напрямую
(например: 'сгруппируй продажи по категориям', 'покажи динамику за квартал',
'сравни regions по выручке')."
                ],
                [
                    'name' => 'response_in_chat',
                    "description" => "Используется для общих вопросов, приветствий, уточнений,
объяснений и любых запросов, не связанных напрямую с анализом данных
пользователя."
                ],

            [
                'name'=>'data_processing',
                'description'=>'data_processing',
            ]
        ];

        foreach ($tasks as $task) {
            Task::updateOrCreate(
                ['name' => $task['name']],
                [
                    'description' => $task['description'],
                ]
            );
        }
    }
}
