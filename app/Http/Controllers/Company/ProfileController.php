<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Собственный профиль пользователя: имя, e-mail, пароль и название компании
 * (последнее — только для тех, у кого есть право manage company).
 */
class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => 'required_with:password|nullable|string',
            'password' => 'sometimes|nullable|string|min:6|confirmed',
            'company_name' => 'sometimes|required|string|max:255',
        ]);

        if (!empty($data['password'])) {
            // Смена пароля только с подтверждением текущего — иначе перехваченный
            // токен позволил бы полностью угнать учётную запись.
            if (!Hash::check($data['current_password'] ?? '', $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'Текущий пароль указан неверно.',
                ]);
            }

            $user->password = Hash::make($data['password']);

            // Старые токены после смены пароля обесцениваются: если учётку
            // увели, смена пароля должна выбросить чужую сессию. Текущий токен
            // сохраняем — иначе пользователь разлогинит сам себя.
            $user->tokens()
                ->where('id', '!=', $request->user()->currentAccessToken()?->id)
                ->delete();
        }

        if (isset($data['name'])) {
            $user->name = $data['name'];
        }

        if (isset($data['email'])) {
            $user->email = $data['email'];
        }

        $user->save();

        if (isset($data['company_name'])) {
            if (!$user->can('manage company')) {
                return response()->json([
                    'message' => 'Недостаточно прав для изменения названия компании.',
                ], 403);
            }

            $company = $user->company;

            if ($company) {
                $company->name = $data['company_name'];
                $company->save();
            }
        }

        $user->load('company');

        return response()->json([
            'user' => array_merge($user->toArray(), [
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'is_company_owner' => $user->isCompanyOwner(),
            ]),
        ]);
    }
}
