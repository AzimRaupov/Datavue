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
                'name' => 'determine_changes',
                'description' => 'Определяет, какие виджеты необходимо создать, обновить или удалить на основе инструкции пользователя.',
            ],
            [
                'name' => 'updating_dashboard',
                'description'=>'updating_dashboard'
            ],
                [
                    'name'=>'detect_schema_dashboard',
                    'description'=>'Определение схемы дашборда',
                ],
                [
                    'name'=>'define_task',
                    'description'=>'define_task'
                ],
                [
                    'name'=>'re_generate_dashboard',
                    'description' => "Используется, когда пользователь просит пересоздать, обновить,
перестроить или изменить уже существующий дашборд с учетом новых требований.
Подходит для запросов на изменение визуализаций, добавление или удаление графиков,
изменение группировок, фильтров, метрик, периода, структуры отчета или других
параметров существующего дашборда (например: 'добавь график по регионам',
'измени группировку по категориям', 'покажи данные за прошлый квартал',
'замени круговую диаграмму на столбчатую', 'обнови дашборд')."
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
