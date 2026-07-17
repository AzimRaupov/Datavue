<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Widgets\BarChartSeeder;
use Database\Seeders\Widgets\DonutChartSeeder;
use Database\Seeders\Widgets\MiniCountersSeeder;
use Database\Seeders\Widgets\MultiSeriesTrendSeeder;
use Database\Seeders\Widgets\PieChartSeeder;
use Database\Seeders\Widgets\ScatterPlotSeeder;
use Database\Seeders\Widgets\TableSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call([
            UserSeeder::class,
            MultiSeriesTrendSeeder::class,
            PieChartSeeder::class,
            MiniCountersSeeder::class,
            TableSeeder::class,
            ScatterPlotSeeder::class,
            DonutChartSeeder::class,
            TaskSeeder::class,
            TaskStatusSeeder::class,
            BarChartSeeder::class
        ]);

    }
}
