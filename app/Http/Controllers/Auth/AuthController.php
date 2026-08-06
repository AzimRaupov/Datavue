<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function getUser(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Пользователь не авторизован',
            ], 401);
        }

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Регистрация компании: создаётся сама компания и её первый пользователь,
     * который становится владельцем и получает роль company_admin —
     * то есть полные права на всё внутри своей компании.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'company_name' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6|confirmed',
        ]);

        $result = DB::transaction(function () use ($data) {
            $company = Company::query()->create([
                'name' => $data['company_name'],
                'is_active' => true,
            ]);

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'company_id' => $company->id,
                'password' => Hash::make($data['password']),
                'is_active' => true,
            ]);

            // Владелец компании — защищён от удаления и понижения в правах.
            $company->owner_id = $user->id;
            $company->save();

            $user->assignRole('company_admin');

            return [$company, $user];
        });

        [, $user] = $result;

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $this->userPayload($user),
            'token' => $token,
        ], 201);
    }
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Неверный логин или пароль'
            ], 401);
        }

        // Отключённый сотрудник не должен входить, хотя его учётка сохранена.
        if (!$user->is_active) {
            return response()->json([
                'message' => 'Учётная запись отключена. Обратитесь к администратору компании.',
            ], 403);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $this->userPayload($user),
            'token' => $token
        ]);
    }

    /**
     * Единый формат пользователя для фронта: вместе с компанией, ролями и
     * плоским списком прав — по нему интерфейс решает, что показывать.
     */
    private function userPayload(User $user): array
    {
        $user->load('company');

        return array_merge($user->toArray(), [
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'is_company_owner' => $user->isCompanyOwner(),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Выход выполнен'
        ]);
    }
}
