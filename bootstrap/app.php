<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    /*
    | Подписка на приватный канал проверяется здесь же, где и остальное API, —
    | охраной 'auth:sanctum'.
    |
    | Иначе никак: интерфейс это одностраничное приложение с токеном в заголовке
    | Authorization, а маршрут /broadcasting/auth по умолчанию идёт через 'web',
    | то есть ждёт сессионную куку. Её нет — и подписка на приватный канал
    | не прошла бы ни у кого.
    */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Псевдонимы Spatie нужно регистрировать вручную — начиная с Laravel 11
        // пакет больше не добавляет их в ядро автоматически.
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
