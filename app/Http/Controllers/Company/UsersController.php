<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UsersController extends Controller
{

    private const CUSTOM_ROLE = 'custom';

    public function index(Request $request)
    {
        $user = $request->user();

        $employees = User::query()
            ->ofCompany($user->company_id)

            ->with(['roles:id,name', 'roles.permissions:id,name', 'permissions:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $employee) => $this->present($employee, $user));

        return response()->json([
            'users' => $employees,
            'assignable_roles' => $this->assignableRoles(),

            'permission_groups' => $this->permissionGroups(),

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

        if ($employee->id === $user->id) {
            if (array_key_exists('is_active', $data) && !$data['is_active']) {
                throw ValidationException::withMessages([
                    'is_active' => 'Нельзя отключить собственную учётную запись.',
                ]);
            }

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

            'role' => array_values(array_filter([
                $isUpdate ? 'sometimes' : null,
                'required',
                'string',
                Rule::in([...RolePermissionSeeder::ASSIGNABLE_ROLES, self::CUSTOM_ROLE]),
            ])),

            'permissions' => 'array',
            'permissions.*' => ['string', Rule::in(RolePermissionSeeder::PERMISSIONS)],

            'is_active' => 'sometimes|boolean',
        ];
    }

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

        $granting = $role === self::CUSTOM_ROLE
            ? ($data['permissions'] ?? [])
            : (RolePermissionSeeder::ROLE_PERMISSIONS[$role] ?? []);

        $excess = array_diff($granting, $actor->getAllPermissions()->pluck('name')->all());

        if ($excess !== []) {
            abort(403, 'Нельзя выдать доступ шире собственного: '.implode(', ', $excess));
        }
    }

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

    private function reloadAccess(User $employee): User
    {
        return $employee->load([
            'roles:id,name',
            'roles.permissions:id,name',
            'permissions:id,name',
        ]);
    }

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

            'role' => $role ?? self::CUSTOM_ROLE,

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
            'director' => 'Директор',
        ];

        $descriptions = [
            'company_admin' => 'Полный доступ ко всему в компании, включая сотрудников и роли.',
            'analyst' => 'Создаёт и меняет дашборды, чаты и источники данных. Не управляет сотрудниками.',
            'viewer' => 'Только просмотр дашбордов и данных.',
            'director' => 'Работает только в чате с ИИ-агентом: создаёт разговоры, получает и открывает готовые дашборды. Не видит конструктор, источники и настройки.',
        ];

        return collect(RolePermissionSeeder::ASSIGNABLE_ROLES)
            ->map(fn (string $role) => [
                'name' => $role,
                'label' => $labels[$role] ?? $role,
                'description' => $descriptions[$role] ?? '',

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
