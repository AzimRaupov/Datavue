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
                'name'=>'generate_dashboard',
                'description'=>'generate dashboard',
            ],
            [
                'name'=>'response_in_chat',
                'description'=>'response_in_chat',
            ],
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
