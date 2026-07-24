<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'view dashboards',
            'create dashboards',
            'edit dashboards',
            'delete dashboards',
            'manage users',
            'manage data sources',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $superAdmin = Role::firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => 'web',
        ]);

        $companyAdmin = Role::firstOrCreate([
            'name' => 'company_admin',
            'guard_name' => 'web',
        ]);

        $analyst = Role::firstOrCreate([
            'name' => 'analyst',
            'guard_name' => 'web',
        ]);

        $viewer = Role::firstOrCreate([
            'name' => 'viewer',
            'guard_name' => 'web',
        ]);

        $superAdmin->givePermissionTo(Permission::all());

        $companyAdmin->givePermissionTo([
            'view dashboards',
            'create dashboards',
            'edit dashboards',
            'delete dashboards',
            'manage users',
            'manage data sources',
        ]);

        $analyst->givePermissionTo([
            'view dashboards',
            'create dashboards',
            'edit dashboards',
            'manage data sources',
        ]);

        $viewer->givePermissionTo([
            'view dashboards',
        ]);

        $user = User::find(1);

        if ($user) {
            $user->assignRole('super_admin');
        }
    }
}
