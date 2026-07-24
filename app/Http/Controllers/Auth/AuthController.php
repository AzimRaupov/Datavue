<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
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
            'user' => $user->load([
                'company',
                'roles',
                'permissions',
            ]),
        ]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6|confirmed',
        ]);
        $company = Company::query()->create([
            'name'=> 'Azim',
            'is_active'=>1
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'company_id' => $company->id,
            'password' => Hash::make($data['password']),
            'role'=>'company'
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user->load('company'),
            'token' => $token
        ]);
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

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user->load('company'),
            'token' => $token
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
