<?php

use Database\Seeders\RolePermissionSeeder;

/**
 * Каталог прав — то, что администратор компании видит галочками в настройке
 * доступа сотрудника. Он обязан совпадать со списком прав платформы.
 *
 * Расхождение не ломает ничего заметно, и в этом опасность: добавили право,
 * навесили его на маршрут, а в каталог не внесли — выдать его через интерфейс
 * стало невозможно, и функция просто недоступна никому, кроме администратора.
 * Обратный случай хуже: галочка в каталоге, за которой нет ни одной проверки,
 * выглядит как выданный доступ, но не значит ничего.
 */

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
    // Если у company_admin не окажется какого-то права, компания не сможет
    // пользоваться собственной функцией и починить это будет некому.
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
