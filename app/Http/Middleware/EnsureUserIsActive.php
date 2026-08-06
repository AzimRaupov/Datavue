<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Отключённый администратором сотрудник не должен пользоваться API даже по
 * ранее выданному токену — проверяем флаг на каждом запросе.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->is_active) {
            return response()->json([
                'message' => 'Учётная запись отключена. Обратитесь к администратору компании.',
            ], 403);
        }

        if ($user && $user->company && !$user->company->is_active) {
            return response()->json([
                'message' => 'Компания отключена.',
            ], 403);
        }

        return $next($request);
    }
}
