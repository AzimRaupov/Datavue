<?php

namespace Database\Seeders;

use App\Models\TaskStatus;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TaskStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tasks_statuses = [
            [
                'name'=>'start',
                'description'=>'start'
            ],
            [
                'name'=>'completed',
                'description'=>'completed',
            ],
            [
                'name'=>'failed',
                'description'=>'failed',
            ],
            [
                'name'=>'in_progress',
                'description'=>'in_progress',
            ]
        ];

        foreach ($tasks_statuses as $status) {
            TaskStatus::updateOrCreate(
                ['name' => $status['name']],
                [
                    'description' => $status['description'],
                ]
            );
        }
    }
}
