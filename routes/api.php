<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\Chat\MessageController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\UploadFile\FileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->prefix('company')->group(function () {
    Route::apiResource('chats', ChatController::class);
    Route::apiResource('dashboards', DashboardController::class);
    Route::apiResource('message', MessageController::class);

});

Route::apiResource('/upload', FileController::class);
