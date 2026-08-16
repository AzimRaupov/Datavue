<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Все права платформы. Действуют ВНУТРИ компании пользователя —
     * доступ к чужим компаниям невозможен ни при каких правах
     * (изоляция обеспечивается отдельно, в контроллерах и трейте BelongsToCompany).
     */
    public const PERMISSIONS = [
        // Дашборды
        'view dashboards',
        'create dashboards',
        'edit dashboards',
        'delete dashboards',

        // Написание кода виджета руками.
        //
        // Право отдельное, а не производное от 'edit dashboards': собрать
        // дашборд из виджетов и переставить их может любой аналитик, а вот
        // код виджета выполняется на сервере — это выдаётся адресно.
        'write widget code',

        // Чаты с AI-агентом
        'view chats',
        'create chats',
        'edit chats',
        'delete chats',

        // Источники данных
        'view data sources',
        'manage data sources',

        // Сотрудники и доступы
        'view users',
        'manage users',
        'manage roles',

        // Настройки компании
        'manage company',
    ];

    /**
     * Наборы прав по ролям.
     * company_admin получает ВСЕ права — он полноправный хозяин своей компании.
     */
    public const ROLE_PERMISSIONS = [
        'company_admin' => self::PERMISSIONS,

        'analyst' => [
            'view dashboards',
            'create dashboards',
            'edit dashboards',
            'delete dashboards',
            'write widget code',
            'view chats',
            'create chats',
            'edit chats',
            'delete chats',
            'view data sources',
            'manage data sources',
            'view users',
        ],

        'viewer' => [
            'view dashboards',
            'view chats',
            'view data sources',
        ],
    ];

    /**
     * Роли, которые company_admin может назначать сотрудникам.
     * super_admin сюда намеренно не входит — это платформенная роль.
     */
    public const ASSIGNABLE_ROLES = ['company_admin', 'analyst', 'viewer'];

    /**
     * Права по разделам — для настройки доступа сотрудника вручную.
     *
     * Роли остаются готовыми наборами на три типовых случая, но набор из трёх
     * вариантов не покрывает всего: «пусть смотрит дашборды, но не видит
     * подключения к базам» роли не выражают. Отсюда режим особых прав —
     * администратор собирает доступ по галочкам.
     *
     * Почему именно так, а не «свои роли компании»: роли в spatie здесь общие
     * на всю платформу (config/permission.php: 'teams' => false). Дай мы
     * администратору править роль «Аналитик» — он менял бы её сразу у всех
     * компаний сервиса. Права же выдаются пользователю напрямую и живут
     * только у него.
     *
     * @var array<string, array{label: string, items: array<string, string>}>
     */
    public const PERMISSION_GROUPS = [
        'dashboards' => [
            'label' => 'Дашборды',
            'items' => [
                'view dashboards' => 'Смотреть дашборды',
                'create dashboards' => 'Создавать дашборды',
                'edit dashboards' => 'Изменять дашборды и собирать виджеты',
                'delete dashboards' => 'Удалять дашборды',
                'write widget code' => 'Писать запросы и код виджетов',
            ],
        ],

        'chats' => [
            'label' => 'Работа с агентом',
            'items' => [
                'view chats' => 'Читать переписку с агентом',
                'create chats' => 'Писать агенту и заводить разговоры',
                'edit chats' => 'Переименовывать разговоры',
                'delete chats' => 'Удалять разговоры',
            ],
        ],

        'data_sources' => [
            'label' => 'Источники данных',
            'items' => [
                'view data sources' => 'Видеть подключённые источники',
                'manage data sources' => 'Подключать, обновлять и удалять источники',
            ],
        ],

        'company' => [
            'label' => 'Компания',
            'items' => [
                'view users' => 'Видеть список сотрудников',
                'manage users' => 'Заводить и отключать сотрудников',
                'manage roles' => 'Настраивать доступ сотрудников',
                'manage company' => 'Менять настройки компании и лимит ИИ',
            ],
        ],
    ];

    /**
     * Права, без которых администратор потеряет управление компанией.
     *
     * Снять их с самого себя нельзя: иначе один неверный набор галочек
     * оставляет компанию без единого человека, способного раздать доступ,
     * и починить это можно только из базы.
     */
    public const SELF_LOCKOUT_PERMISSIONS = ['view users', 'manage users', 'manage roles'];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Платформенная роль поверх всех компаний — только для владельцев сервиса.
        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $superAdmin->syncPermissions(Permission::all());

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            // syncPermissions, а не givePermissionTo: при повторном запуске сидера
            // роль приводится ровно к заявленному набору, без накопления старых прав.
            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->backfillExistingUsers();
    }

    /**
     * Приводит в порядок данные, созданные до появления этой системы ролей.
     */
    private function backfillExistingUsers(): void
    {
        // 1. У каждой компании должен быть владелец — иначе некого защищать
        //    от удаления и понижения в правах. Берём первого её пользователя.
        Company::query()
            ->whereNull('owner_id')
            ->each(function (Company $company) {
                $firstUserId = $company->users()->orderBy('id')->value('id');

                if ($firstUserId) {
                    $company->owner_id = $firstUserId;
                    $company->save();
                }
            });

        // 2. Пользователям без единой роли выдаём права, иначе после включения
        //    проверок они потеряют доступ к собственным данным.
        User::query()
            ->with('company')
            ->whereDoesntHave('roles')
            ->each(function (User $user) {
                $user->assignRole($user->isCompanyOwner() ? 'company_admin' : 'analyst');
            });

        // 3. Владелец компании обязан быть её администратором.
        User::query()
            ->with('company')
            ->whereNotNull('company_id')
            ->each(function (User $user) {
                if ($user->isCompanyOwner() && !$user->hasRole('company_admin')) {
                    $user->assignRole('company_admin');
                }
            });
    }
}
