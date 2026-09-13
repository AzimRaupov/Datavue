<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Роль «Директор» добавлена в RolePermissionSeeder::ROLE_PERMISSIONS позже,
 * чем начальная выдача прав. Сидер идемпотентен (findOrCreate/syncPermissions),
 * поэтому повторный прогон здесь безопасен и не трогает уже выданные роли —
 * он просто добавляет то, чего раньше не было.
 */
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
