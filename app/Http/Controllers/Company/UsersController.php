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
    public function index(Request $request)
    {
        $user = $request->user();

        $employees = User::query()
            ->ofCompany($user->company_id)
            ->with('roles:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $employee) => $this->present($employee, $user));

        return response()->json([
            'users' => $employees,
            'assignable_roles' => $this->assignableRoles(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'role' => ['required', 'string', Rule::in(RolePermissionSeeder::ASSIGNABLE_ROLES)],
            'is_active' => 'sometimes|boolean',
        ]);

        $employee = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            // Компанию берём у текущего пользователя, а НЕ из запроса —
            // иначе можно было бы создать сотрудника в чужой компании.
            'company_id' => $user->company_id,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $employee->syncRoles([$data['role']]);

        return response()->json([
            'user' => $this->present($employee->load('roles:id,name'), $user),
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        $employee = $this->findEmployee($request, $id);

        return response()->json([
            'user' => $this->present($employee->load('roles:id,name'), $user),
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        $employee = $this->findEmployee($request, $id);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($employee->id)],
            'password' => 'sometimes|nullable|string|min:6|confirmed',
            'role' => ['sometimes', 'required', 'string', Rule::in(RolePermissionSeeder::ASSIGNABLE_ROLES)],
            'is_active' => 'sometimes|boolean',
        ]);

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
            if (isset($data['role']) && $data['role'] !== 'company_admin') {
                throw ValidationException::withMessages([
                    'role' => 'Нельзя понизить собственную роль.',
                ]);
            }

            if (array_key_exists('is_active', $data) && !$data['is_active']) {
                throw ValidationException::withMessages([
                    'is_active' => 'Нельзя отключить собственную учётную запись.',
                ]);
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
            $employee->syncRoles([$data['role']]);
        }

        return response()->json([
            'user' => $this->present($employee->load('roles:id,name'), $user),
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
        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'is_active' => (bool) $employee->is_active,
            'role' => $employee->roles->pluck('name')->first(),
            'is_owner' => $employee->isCompanyOwner(),
            'is_self' => $employee->id === $currentUser->id,
            'created_at' => $employee->created_at,
        ];
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
            ])
            ->all();
    }
}
