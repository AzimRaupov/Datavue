<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Управление сотрудниками компании.
 *
 * Все запросы жёстко ограничены компанией текущего пользователя: сотрудник
 * другой компании не найдётся ни при каких правах (см. findEmployee()).
 */
class UsersController extends Controller
{
    /**
     * Псевдороль «особые права»: доступ собран галочками, роль не назначена.
     * Настоящей ролью быть не может — роли в spatie общие на всю платформу.
     */
    private const CUSTOM_ROLE = 'custom';

    public function index(Request $request)
    {
        $user = $request->user();

        $employees = User::query()
            ->ofCompany($user->company_id)
            // Права тянем вместе со списком: present() показывает действующий
            // доступ каждого, и без этого он спрашивал бы базу на каждого
            // сотрудника отдельно.
            ->with(['roles:id,name', 'roles.permissions:id,name', 'permissions:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $employee) => $this->present($employee, $user));

        return response()->json([
            'users' => $employees,
            'assignable_roles' => $this->assignableRoles(),
            // Каталог для режима особых прав. Отдаём всем, кто видит список:
            // подписи нужны и просто чтобы показать, что у сотрудника открыто.
            'permission_groups' => $this->permissionGroups(),
            // Настраивать доступ вправе не каждый, кто заводит сотрудников,
            // — фронт по этому флагу прячет редактор прав.
            'can_manage_roles' => $user->can('manage roles'),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate($this->rules($request));

        $this->authorizeAccessChange($request, $data);

        $employee = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            // Компанию берём у текущего пользователя, а НЕ из запроса —
            // иначе можно было бы создать сотрудника в чужой компании.
            'company_id' => $user->company_id,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->applyAccess($employee, $data['role'], $data['permissions'] ?? []);

        return response()->json([
            'user' => $this->present($this->reloadAccess($employee), $user),
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        $employee = $this->findEmployee($request, $id);

        return response()->json([
            'user' => $this->present($this->reloadAccess($employee), $user),
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        $employee = $this->findEmployee($request, $id);

        $data = $request->validate($this->rules($request, $employee));

        $this->authorizeAccessChange($request, $data);

        $isOwner = $employee->isCompanyOwner();

        // Владельца компании нельзя разжаловать или отключить — иначе компания
        // может остаться вообще без администратора.
        if ($isOwner && isset($data['role']) && $data['role'] !== 'company_admin') {
            throw ValidationException::withMessages([
                'role' => 'Нельзя изменить роль владельца компании.',
            ]);
        }

        if ($isOwner && array_key_exists('is_active', $data) && !$data['is_active']) {
            throw ValidationException::withMessages([
                'is_active' => 'Нельзя отключить владельца компании.',
            ]);
        }

        // Запрет снять права с самого себя — чтобы админ не заблокировал себе доступ.
        if ($employee->id === $user->id) {
            if (array_key_exists('is_active', $data) && !$data['is_active']) {
                throw ValidationException::withMessages([
                    'is_active' => 'Нельзя отключить собственную учётную запись.',
                ]);
            }

            // Роль менять себе можно только на особые права — и только такие,
            // в которых управление доступом осталось. Иначе компания остаётся
            // без единого человека, способного что-то раздать.
            if (isset($data['role']) && $data['role'] !== 'company_admin') {
                $kept = $data['role'] === self::CUSTOM_ROLE
                    ? array_diff(RolePermissionSeeder::SELF_LOCKOUT_PERMISSIONS, $data['permissions'] ?? [])
                    : RolePermissionSeeder::SELF_LOCKOUT_PERMISSIONS;

                if ($kept !== []) {
                    throw ValidationException::withMessages([
                        'role' => 'Нельзя снять с себя управление доступом — иначе вернуть его будет некому.',
                    ]);
                }
            }
        }

        $employee->fill(array_filter([
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
        ], fn ($value) => $value !== null));

        if (array_key_exists('is_active', $data)) {
            $employee->is_active = $data['is_active'];
        }

        if (!empty($data['password'])) {
            $employee->password = Hash::make($data['password']);
            // Смена пароля обесценивает старые токены — разлогиниваем сотрудника.
            $employee->tokens()->delete();
        }

        $employee->save();

        if (isset($data['role'])) {
            $this->applyAccess($employee, $data['role'], $data['permissions'] ?? []);
        }

        return response()->json([
            'user' => $this->present($this->reloadAccess($employee), $user),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $employee = $this->findEmployee($request, $id);

        if ($employee->isCompanyOwner()) {
            return response()->json([
                'message' => 'Нельзя удалить владельца компании.',
            ], 422);
        }

        if ($employee->id === $user->id) {
            return response()->json([
                'message' => 'Нельзя удалить собственную учётную запись.',
            ], 422);
        }

        $employee->tokens()->delete();
        $employee->delete();

        return response()->json(['message' => 'Сотрудник удалён.']);
    }

    /**
     * Правила запроса. Общие для создания и правки — расходятся только
     * в обязательности полей.
     *
     * @return array<string, mixed>
     */
    private function rules(Request $request, ?User $employee = null): array
    {
        $isUpdate = $employee !== null;
        $sometimes = $isUpdate ? 'sometimes|' : '';

        return [
            'name' => $sometimes.'required|string|max:255',
            'email' => $isUpdate
                ? ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($employee->id)]
                : ['required', 'email', 'unique:users,email'],
            'password' => $isUpdate
                ? 'sometimes|nullable|string|min:6|confirmed'
                : 'required|string|min:6|confirmed',

            // Кроме готовых ролей допустим режим особых прав: тогда доступ
            // задаётся списком ниже, а роль сотруднику не назначается вовсе.
            'role' => array_values(array_filter([
                $isUpdate ? 'sometimes' : null,
                'required',
                'string',
                Rule::in([...RolePermissionSeeder::ASSIGNABLE_ROLES, self::CUSTOM_ROLE]),
            ])),

            // Список закрыт каталогом: произвольную строку в права не записать,
            // иначе появилось бы «право», под которое нет ни одной проверки.
            'permissions' => 'array',
            'permissions.*' => ['string', Rule::in(RolePermissionSeeder::PERMISSIONS)],

            'is_active' => 'sometimes|boolean',
        ];
    }

    /**
     * Настройка доступа по галочкам — отдельное право, более сильное, чем
     * заведение сотрудников.
     *
     * Разница по смыслу: «manage users» позволяет выдать один из готовых
     * наборов, где что можно, а чего нельзя, решено заранее. «manage roles» —
     * собрать набор самому, включая право раздавать доступ дальше. Второе
     * должно выдаваться отдельно и осознанно.
     */
    private function authorizeAccessChange(Request $request, array $data): void
    {
        $actor = $request->user();
        $role = $data['role'] ?? null;

        if ($role === null) {
            return;
        }

        if ($role === self::CUSTOM_ROLE && !$actor->can('manage roles')) {
            abort(403, 'Нужно право «Настраивать доступ сотрудников».');
        }

        // Выдать можно только то, что есть у самого себя.
        //
        // Без этой проверки право «Заводить сотрудников» означало бы полный
        // захват компании: достаточно завести сотрудника с ролью
        // администратора, задав ему пароль, и войти под ним. Ровно так же
        // и с особыми правами — набор не должен выходить за пределы своего.
        $granting = $role === self::CUSTOM_ROLE
            ? ($data['permissions'] ?? [])
            : (RolePermissionSeeder::ROLE_PERMISSIONS[$role] ?? []);

        $excess = array_diff($granting, $actor->getAllPermissions()->pluck('name')->all());

        if ($excess !== []) {
            abort(403, 'Нельзя выдать доступ шире собственного: '.implode(', ', $excess));
        }
    }

    /**
     * Записывает доступ сотрудника: готовая роль или особый набор прав.
     *
     * Сотрудник всегда в одном из двух состояний, и смешивать их нельзя.
     * У роли есть свои права; выдай мы поверх неё ещё и личные, снять
     * что-то, входящее в роль, стало бы невозможно — spatie складывает
     * права роли и личные, а не вычитает. Поэтому при переходе в особый
     * режим роль снимается, а при возврате к роли личные права стираются.
     *
     * @param  array<int, string>  $permissions
     */
    private function applyAccess(User $employee, string $role, array $permissions): void
    {
        if ($role === self::CUSTOM_ROLE) {
            $employee->syncRoles([]);
            $employee->syncPermissions($permissions);

            return;
        }

        $employee->syncPermissions([]);
        $employee->syncRoles([$role]);
    }

    /**
     * Перечитывает роли и права после записи.
     *
     * Без сброса связей ответ показывал бы доступ, который был до сохранения:
     * отношения уже загружены в память, и обновление их не трогает.
     */
    private function reloadAccess(User $employee): User
    {
        return $employee->load([
            'roles:id,name',
            'roles.permissions:id,name',
            'permissions:id,name',
        ]);
    }

    /**
     * Находит сотрудника ТОЛЬКО внутри компании текущего пользователя.
     * Для чужого сотрудника вернётся 404 — существование чужих учёток не раскрывается.
     */
    private function findEmployee(Request $request, $id): User
    {
        return User::query()
            ->ofCompany($request->user()->company_id)
            ->findOrFail($id);
    }

    private function present(User $employee, User $currentUser): array
    {
        $role = $employee->roles->pluck('name')->first();

        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'is_active' => (bool) $employee->is_active,
            // Роли нет — значит доступ собран галочками (см. applyAccess).
            'role' => $role ?? self::CUSTOM_ROLE,
            // Действующий доступ, а не то, что подразумевает роль: по нему
            // видно, что сотруднику реально открыто, без чтения таблицы ролей.
            'permissions' => $employee->getAllPermissions()
                ->pluck('name')
                ->sort()
                ->values()
                ->all(),
            'is_owner' => $employee->isCompanyOwner(),
            'is_self' => $employee->id === $currentUser->id,
            'created_at' => $employee->created_at,
        ];
    }

    /**
     * Каталог прав по разделам — для редактора особых прав.
     *
     * @return array<int, array{key: string, label: string, items: array<int, array{name: string, label: string}>}>
     */
    private function permissionGroups(): array
    {
        $groups = [];

        foreach (RolePermissionSeeder::PERMISSION_GROUPS as $key => $group) {
            $items = [];

            foreach ($group['items'] as $name => $label) {
                $items[] = ['name' => $name, 'label' => $label];
            }

            $groups[] = ['key' => $key, 'label' => $group['label'], 'items' => $items];
        }

        return $groups;
    }

    private function assignableRoles(): array
    {
        $labels = [
            'company_admin' => 'Администратор компании',
            'analyst' => 'Аналитик',
            'viewer' => 'Наблюдатель',
        ];

        $descriptions = [
            'company_admin' => 'Полный доступ ко всему в компании, включая сотрудников и роли.',
            'analyst' => 'Создаёт и меняет дашборды, чаты и источники данных. Не управляет сотрудниками.',
            'viewer' => 'Только просмотр дашбордов и данных.',
        ];

        return collect(RolePermissionSeeder::ASSIGNABLE_ROLES)
            ->map(fn (string $role) => [
                'name' => $role,
                'label' => $labels[$role] ?? $role,
                'description' => $descriptions[$role] ?? '',
                // Состав роли показываем сразу: администратор должен видеть,
                // что именно выдаёт, не сверяясь с документацией. Он же
                // становится отправной точкой при переходе к особым правам.
                'permissions' => RolePermissionSeeder::ROLE_PERMISSIONS[$role] ?? [],
            ])
            ->push([
                'name' => self::CUSTOM_ROLE,
                'label' => 'Особые права',
                'description' => 'Доступ собирается галочками — когда ни один готовый набор не подходит.',
                'permissions' => [],
            ])
            ->all();
    }
}
