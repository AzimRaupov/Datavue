<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(RolePermissionSeeder::class)->run();
    }

    public function down(): void
    {
        Role::where('name', 'director')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
