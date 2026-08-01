<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $company = Company::query()->updateOrCreate(
            ['name' => 'Start Coding'],
            [
                'is_active' => true,
            ]
        );

        $user = User::query()->updateOrCreate(
            ['email' => 'zmraupov@gmail.com'],
            [
                'name' => 'Start Coding',
                'password' => Hash::make('zmraupov@gmail.com'),
                'company_id' => $company->id,
            ]
        );
        $role = Role::firstOrCreate([
            'name' => 'company_admin',
            'guard_name' => 'web',
        ]);

        $user->assignRole($role);
    }
}
