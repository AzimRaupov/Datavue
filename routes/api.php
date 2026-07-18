<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\Chat\MessageController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\DataSource\DataSourceTypeController;
use App\Http\Controllers\UploadFile\FileController;
use App\Http\Controllers\Widget\WidgetRunController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/test', function (Request $request) {
    $router = new \App\Helpers\Task\RouterTask(1,1);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->prefix('company')->group(function () {
    Route::apiResource('chats', ChatController::class);
    Route::apiResource('dashboards', DashboardController::class);
    Route::apiResource('messages', MessageController::class);
    Route::post('get-widget-content/{id}', [WidgetRunController::class, 'run']);
    Route::apiResource('/data_source_types', DataSourceTypeController::class);
});

Route::apiResource('/upload', FileController::class);
