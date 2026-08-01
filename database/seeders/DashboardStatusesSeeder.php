<?php

namespace Database\Seeders;

use App\Models\DashboardStatus;
use App\Models\Task;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DashboardStatusesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = [
            ['name'=>'generating_scheme'],
            ['name'=>'generating_widgets'],
            ['name'=>'completed'],
            ['name'=>'empty'],
            ['name'=>'reviewing'],
            ['name'=>'failed'],
        ];

        foreach ($statuses as $status) {
            DashboardStatus::updateOrCreate(
                ['name' => $status['name']],
                [
                    'description' => $status['description'] ?? null,
                ]
            );
        }
    }
}
