<?php

use Database\Seeders\RolePermissionSeeder;

function catalogPermissions(): array
{
    $names = [];

    foreach (RolePermissionSeeder::PERMISSION_GROUPS as $group) {
        $names = array_merge($names, array_keys($group['items']));
    }

    return $names;
}

it('показывает в каталоге ровно те права, что есть у платформы', function () {
    $catalog = catalogPermissions();

    expect(array_diff(RolePermissionSeeder::PERMISSIONS, $catalog))->toBe([])
        ->and(array_diff($catalog, RolePermissionSeeder::PERMISSIONS))->toBe([]);
});

it('не повторяет право в двух разделах', function () {
    $catalog = catalogPermissions();

    expect(count($catalog))->toBe(count(array_unique($catalog)));
});

it('раздаёт ролям только существующие права', function () {
    foreach (RolePermissionSeeder::ROLE_PERMISSIONS as $role => $permissions) {
        expect(array_diff($permissions, RolePermissionSeeder::PERMISSIONS))
            ->toBe([], "роль {$role} ссылается на несуществующее право");
    }
});

it('назначает только те роли, состав которых описан', function () {
    foreach (RolePermissionSeeder::ASSIGNABLE_ROLES as $role) {
        expect(RolePermissionSeeder::ROLE_PERMISSIONS)->toHaveKey($role);
    }
});

it('защищает от самоблокировки существующими правами', function () {
    expect(array_diff(
        RolePermissionSeeder::SELF_LOCKOUT_PERMISSIONS,
        RolePermissionSeeder::PERMISSIONS
    ))->toBe([]);
});

it('оставляет администратора компании полноправным', function () {

    expect(array_diff(
        RolePermissionSeeder::PERMISSIONS,
        RolePermissionSeeder::ROLE_PERMISSIONS['company_admin']
    ))->toBe([]);
});

it('не даёт наблюдателю ничего, кроме просмотра', function () {
    foreach (RolePermissionSeeder::ROLE_PERMISSIONS['viewer'] as $permission) {
        expect($permission)->toStartWith('view ');
    }
});

it('не даёт аналитику управлять сотрудниками и компанией', function () {
    expect(RolePermissionSeeder::ROLE_PERMISSIONS['analyst'])
        ->not->toContain('manage users')
        ->not->toContain('manage roles')
        ->not->toContain('manage company');
});
