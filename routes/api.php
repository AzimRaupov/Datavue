<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\Chat\MessageController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\DataSource\DataSourceConnectionController;
use App\Http\Controllers\DataSource\DataSourceTypeController;
use App\Http\Controllers\UploadFile\FileController;
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


Route::middleware('auth:sanctum')->prefix('company')->group(function () {
    Route::apiResource('chats', ChatController::class);
    Route::apiResource('dashboards', DashboardController::class);
    Route::apiResource('messages', MessageController::class);
    Route::post('get-widget-content/{id}', [WidgetRunController::class, 'run']);
    Route::prefix('data_source')
        ->name('data_source.')
        ->group(function () {

            Route::apiResource('types', DataSourceTypeController::class);

            Route::post('{id}/connection', [DataSourceConnectionController::class, 'query'])->name('connection.query');
        });


    Route::prefix('settings')->group(function () {
        Route::apiResource('users', \App\Http\Controllers\Company\UsersController::class);
        Route::post('/profile', [\App\Http\Controllers\Company\ProfileController::class, 'update']);
    });

});

Route::apiResource('/upload', FileController::class);
