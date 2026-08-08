<?php

namespace Database\Seeders;

use App\Models\DashboardStatus;
use App\Models\User;
use Database\Seeders\Widgets\BarChartSeeder;
use Database\Seeders\Widgets\ComboSeeder;
use Database\Seeders\Widgets\FunnelSeeder;
use Database\Seeders\Widgets\HeatmapSeeder;
use Database\Seeders\Widgets\LineChartSeeder;
use Database\Seeders\Widgets\MapSeeder;
use Database\Seeders\Widgets\MiniCountersSeeder;
use Database\Seeders\Widgets\PieChartSeeder;
use Database\Seeders\Widgets\RadarSeeder;
use Database\Seeders\Widgets\RadialSeeder;
use Database\Seeders\Widgets\ScatterPlotSeeder;
use Database\Seeders\Widgets\TableSeeder;
use Database\Seeders\Widgets\TreemapSeeder;
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
            RolePermissionSeeder::class,
            UserSeeder::class,
            TaskSeeder::class,
            TaskStatusSeeder::class,
            DataSourceTypesSeeder::class,
            DashboardStatusesSeeder::class,

            // Каталог виджетов: одно семейство = один сидер, типы внутри него.
            MiniCountersSeeder::class,
            BarChartSeeder::class,
            LineChartSeeder::class,
            PieChartSeeder::class,
            RadialSeeder::class,
            ComboSeeder::class,
            TableSeeder::class,
            ScatterPlotSeeder::class,
            RadarSeeder::class,
            HeatmapSeeder::class,
            TreemapSeeder::class,
            FunnelSeeder::class,
            MapSeeder::class,
        ]);

    }
}
