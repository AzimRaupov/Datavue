<?php

namespace Database\Seeders;

use App\Models\DataSourceType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DataSourceTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            [
                'name'=>'duckdb',
                'description'=>'duckdb',
            ],
            [
                'name' => 'mysql',
                'description' => 'mysql',
            ],
            [
                'name' => 'postgres',
                'description' => 'postgres',
            ],
            [
                'name' => 'sqlite',
                'description' => 'sqlite',
            ],
        ];

        foreach ($types as $type) {
            DataSourceType::updateOrCreate(
                ['name' => $type['name']],
                [
                    'description' => $type['description'],
                ]
            );
        }
    }
}
