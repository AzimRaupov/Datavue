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
                'name'=>'generating_widget_instructions',
                'description'=>'generating_widget_instructions'
            ],
            [
                'name'=>'determine_data_source_groups',
                'description'=>'determine_data_source_groups'
            ],
            [
                'name'=>'data_source_grouping',
                'description'=>'data_source_grouping'
            ],
            [
                'name'=>'review_and_correction_widgets',
                'description'=>'review_and_correction_widgets'
            ],
            [
             'name'=>'dashboard_creating_plan',
             'description'=>'dashboard_creating_plan',
            ],
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
                    'description' => "Изменение уже существующего дашборда по ПРЯМОМУ РАСПОРЯЖЕНИЮ пользователя.
Выбирается только тогда, когда пользователь в повелительном наклонении велит изменить дашборд:
'добавь график по регионам', 'удали второй виджет', 'замени круговую диаграмму на столбчатую',
'переведи заголовки на английский', 'отсортируй по убыванию', 'обнови дашборд'.
НЕ выбирается, если пользователь задаёт вопрос или просит совет о дашборде
(например 'что можно добавить?', 'чего не хватает?', 'что порекомендуешь?',
'ознакомься с виджетами и предложи идеи') — это разговор, а не распоряжение,
для него существует задача response_in_chat."
                ],
                [
                  'name'=>'generate_widgets_dashboard',
                  'description'=>'Генератсия виджетов.',
                ],
                [
                    'name' => 'generate_dashboard',
                    "description" => "Построение НОВОГО дашборда с нуля по распоряжению пользователя.
Выбирается, когда пользователь велит построить визуализацию, а подходящего дашборда ещё нет
(либо он явно просит отдельный дашборд по новой теме): 'сделай аналитику продаж',
'построй дашборд по клиентам', 'сгруппируй продажи по категориям и покажи графиком',
'визуализируй динамику за квартал'.
НЕ выбирается для вопросов и просьб о совете ('какие данные есть?', 'что посоветуешь построить?')
— для них существует задача response_in_chat, агент которой сам может проанализировать данные
и ответить текстом."
                ],
                [
                    'name' => 'response_in_chat',
                    "description" => "Ответ пользователю в чате — задача по умолчанию для ЛЮБОГО сообщения,
которое не является прямым распоряжением изменить или построить дашборд.
Отвечающий агент видит текущий дашборд с виджетами, схему источника данных и может
выполнять запросы к данным, поэтому эта задача подходит в том числе для вопросов
О ДАННЫХ и О ДАШБОРДЕ. Примеры: 'что показывает второй виджет?', 'какие данные у меня есть?',
'сколько всего клиентов?', 'проанализируй продажи', 'чего не хватает в дашборде?',
'что порекомендуешь добавить?', 'ознакомься с виджетами и предложи идеи',
а также приветствия, уточнения, возражения и вопросы о возможностях платформы."
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
