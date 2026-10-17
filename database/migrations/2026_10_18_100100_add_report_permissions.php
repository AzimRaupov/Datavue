<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // Сидер заводит права «view reports» / «manage reports» и раздаёт их ролям.
        app(RolePermissionSeeder::class)->run();
    }

    public function down(): void
    {
        Permission::whereIn('name', ['view reports', 'manage reports'])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
