<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\Chat\MessageController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\DataSource\DataSourceConnectionController;
use App\Http\Controllers\DataSource\DataSourceTypeController;
use App\Http\Controllers\UploadFile\FileController;
use App\Http\Controllers\Widget\WidgetCatalogController;
use App\Http\Controllers\Widget\WidgetRunController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::post('/test', function (Request $request) {

    $user = $request->user();

    return response()->json([
        'user' => $user,

        'roles' => $user->getRoleNames(),

        'permissions' => $user->getAllPermissions()
            ->pluck('name'),
    ]);
})->middleware('auth:sanctum');


Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/get-user', [AuthController::class, 'getUser'])->middleware('auth:sanctum');


Route::middleware(['auth:sanctum', 'active'])->prefix('company')->group(function () {
    // Права разделены на чтение и изменение: роль viewer видит дашборды и чаты,
    // но не может их создавать, менять или удалять.
    Route::get('chats', [ChatController::class, 'index'])->middleware('permission:view chats');
    Route::get('chats/{chat}', [ChatController::class, 'show'])->middleware('permission:view chats');
    Route::post('chats', [ChatController::class, 'store'])->middleware('permission:create chats');
    Route::match(['put', 'patch'], 'chats/{chat}', [ChatController::class, 'update'])->middleware('permission:edit chats');
    Route::delete('chats/{chat}', [ChatController::class, 'destroy'])->middleware('permission:delete chats');

    Route::get('dashboards', [DashboardController::class, 'index'])->middleware('permission:view dashboards');
    Route::get('dashboards/{dashboard}', [DashboardController::class, 'show'])->middleware('permission:view dashboards');
    Route::post('dashboards', [DashboardController::class, 'store'])->middleware('permission:create dashboards');
    Route::match(['put', 'patch'], 'dashboards/{dashboard}', [DashboardController::class, 'update'])->middleware('permission:edit dashboards');
    Route::delete('dashboards/{dashboard}', [DashboardController::class, 'destroy'])->middleware('permission:delete dashboards');

    // Сообщения агенту — это работа с чатом, поэтому право на создание чата.
    Route::get('messages', [MessageController::class, 'index'])->middleware('permission:view chats');
    Route::get('messages/{message}', [MessageController::class, 'show'])->middleware('permission:view chats');
    Route::post('messages', [MessageController::class, 'store'])->middleware('permission:create chats');

    Route::post('get-widget-content/{id}', [WidgetRunController::class, 'run'])
        ->middleware('permission:view dashboards');

    Route::get('widgets/catalog', [WidgetCatalogController::class, 'index'])
        ->middleware('permission:view dashboards');

    Route::prefix('data_source')
        ->name('data_source.')
        ->group(function () {

            Route::get('types', [DataSourceTypeController::class, 'index'])
                ->middleware('permission:view data sources');

            Route::post('{id}/connection', [DataSourceConnectionController::class, 'query'])
                ->middleware('permission:manage data sources')
                ->name('connection.query');
        });


    Route::prefix('settings')->group(function () {
        // Управление сотрудниками доступно только тем, у кого есть на это право
        // (по умолчанию — роль company_admin). Просмотр отделён от изменения.
        Route::get('users', [\App\Http\Controllers\Company\UsersController::class, 'index'])
            ->middleware('permission:view users');
        Route::get('users/{user}', [\App\Http\Controllers\Company\UsersController::class, 'show'])
            ->middleware('permission:view users');

        Route::middleware('permission:manage users')->group(function () {
            Route::post('users', [\App\Http\Controllers\Company\UsersController::class, 'store']);
            Route::match(['put', 'patch'], 'users/{user}', [\App\Http\Controllers\Company\UsersController::class, 'update']);
            Route::delete('users/{user}', [\App\Http\Controllers\Company\UsersController::class, 'destroy']);
        });

        // Свой профиль может менять любой авторизованный пользователь.
        Route::post('/profile', [\App\Http\Controllers\Company\ProfileController::class, 'update']);
    });

});

// Загрузка файлов ОБЯЗАТЕЛЬНО за авторизацией: раньше маршрут был публичным,
// и любой аноним мог складывать файлы на сервер без ограничений.
Route::middleware(['auth:sanctum', 'active', 'permission:manage data sources'])
    ->post('/upload', [FileController::class, 'store']);
