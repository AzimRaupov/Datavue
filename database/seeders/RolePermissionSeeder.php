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

    public const PERMISSIONS = [

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

        'view alerts',
        'manage alerts',

        'write alert code',

        'view users',
        'manage users',
        'manage roles',

        'manage company',
    ];

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
            'view alerts',
            'manage alerts',
            'write alert code',
            'view users',
        ],

        'viewer' => [
            'view dashboards',
            'view chats',
            'view data sources',
            'view alerts',
        ],

        'director' => [
            'view chats',
            'create chats',
            'edit chats',
            'delete chats',
            'view dashboards',
            'view data sources',
        ],
    ];

    public const ASSIGNABLE_ROLES = ['company_admin', 'analyst', 'viewer', 'director'];

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

        'alerts' => [
            'label' => 'Алерты',
            'items' => [
                'view alerts' => 'Видеть алерты и их историю проверок',
                'manage alerts' => 'Создавать, менять и удалять алерты',
                'write alert code' => 'Писать SQL и Python-условия алертов',
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

    public const SELF_LOCKOUT_PERMISSIONS = ['view users', 'manage users', 'manage roles'];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $superAdmin->syncPermissions(Permission::all());

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');

            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->backfillExistingUsers();
    }

    private function backfillExistingUsers(): void
    {

        Company::query()
            ->whereNull('owner_id')
            ->each(function (Company $company) {
                $firstUserId = $company->users()->orderBy('id')->value('id');

                if ($firstUserId) {
                    $company->owner_id = $firstUserId;
                    $company->save();
                }
            });

        User::query()
            ->with('company')
            ->whereDoesntHave('roles')
            ->each(function (User $user) {
                $user->assignRole($user->isCompanyOwner() ? 'company_admin' : 'analyst');
            });

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
