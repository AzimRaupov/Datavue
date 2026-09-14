<?php

use App\Http\Controllers\Alert\AlertController;
use App\Http\Controllers\Alert\AlertRunController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\Chat\ExportController;
use App\Http\Controllers\Chat\MessageController;
use App\Http\Controllers\Dashboard\DashboardBuilderController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\DashboardWidgetController;
use App\Http\Controllers\Dashboard\WorkspaceController;
use App\Http\Controllers\DataSource\DataSourceConnectionController;
use App\Http\Controllers\DataSource\DataSourceController;
use App\Http\Controllers\DataSource\DataSourceTypeController;
use App\Http\Controllers\UploadFile\FileController;
use App\Http\Controllers\Widget\WidgetCatalogController;
use App\Http\Controllers\Widget\WidgetRunController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::post('/get-user', [AuthController::class, 'getUser'])->middleware(['auth:sanctum', 'active']);

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'active'])->prefix('company')->group(function () {

    Route::get('chats', [ChatController::class, 'index'])->middleware('permission:view chats');
    Route::get('chats/{chat}', [ChatController::class, 'show'])->middleware('permission:view chats');
    Route::post('chats', [ChatController::class, 'store'])->middleware('permission:create chats');
    Route::match(['put', 'patch'], 'chats/{chat}', [ChatController::class, 'update'])->middleware('permission:edit chats');
    Route::delete('chats/{chat}', [ChatController::class, 'destroy'])->middleware('permission:delete chats');

    Route::get('workspaces', [WorkspaceController::class, 'index'])
        ->middleware('permission:view dashboards');
    Route::post('workspaces', [WorkspaceController::class, 'store'])
        ->middleware('permission:create dashboards');
    Route::get('workspaces/by-dashboard/{dashboard}', [WorkspaceController::class, 'byDashboard'])
        ->middleware('permission:view dashboards');
    Route::get('workspaces/by-chat/{chat}', [WorkspaceController::class, 'byChat'])
        ->middleware('permission:view chats');
    Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show'])
        ->middleware('permission:view dashboards');
    Route::match(['put', 'patch'], 'workspaces/{workspace}', [WorkspaceController::class, 'update'])
        ->middleware('permission:edit dashboards');
    Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'destroy'])
        ->middleware('permission:delete dashboards');

    Route::post('workspaces/{workspace}/chat', [WorkspaceController::class, 'attachChat'])
        ->middleware('permission:create chats');

    Route::get('workspaces/{workspace}/alerts', [AlertController::class, 'index'])
        ->middleware('permission:view alerts');
    Route::post('workspaces/{workspace}/alerts', [AlertController::class, 'store'])
        ->middleware('permission:manage alerts');
    Route::get('workspaces/{workspace}/alerts/schema', [AlertRunController::class, 'schema'])
        ->middleware('permission:manage alerts');
    Route::post('workspaces/{workspace}/alerts/preview', [AlertRunController::class, 'preview'])
        ->middleware('permission:manage alerts');

    Route::get('alerts/{alert}', [AlertController::class, 'show'])
        ->middleware('permission:view alerts');
    Route::match(['put', 'patch'], 'alerts/{alert}', [AlertController::class, 'update'])
        ->middleware('permission:manage alerts');
    Route::delete('alerts/{alert}', [AlertController::class, 'destroy'])
        ->middleware('permission:manage alerts');
    Route::post('alerts/{alert}/toggle', [AlertController::class, 'toggle'])
        ->middleware('permission:manage alerts');
    Route::post('alerts/{alert}/run', [AlertRunController::class, 'run'])
        ->middleware('permission:manage alerts');
    Route::get('alerts/{alert}/history', [AlertRunController::class, 'history'])
        ->middleware('permission:view alerts');

    Route::get('dashboards', [DashboardController::class, 'index'])->middleware('permission:view dashboards');
    Route::get('dashboards/{dashboard}', [DashboardController::class, 'show'])->middleware('permission:view dashboards');
    Route::post('dashboards', [DashboardController::class, 'store'])->middleware('permission:create dashboards');
    Route::match(['put', 'patch'], 'dashboards/{dashboard}', [DashboardController::class, 'update'])->middleware('permission:edit dashboards');

    Route::match(['put', 'patch'], 'dashboards/{dashboard}/widgets', [DashboardController::class, 'updateWidgets'])
        ->middleware('permission:edit dashboards');
    Route::delete('dashboards/{dashboard}', [DashboardController::class, 'destroy'])->middleware('permission:delete dashboards');

    Route::middleware('permission:edit dashboards')->group(function () {
        Route::get('dashboards/{dashboard}/edit', [DashboardBuilderController::class, 'edit']);
        Route::get('dashboards/{dashboard}/schema', [DashboardBuilderController::class, 'schema']);

        Route::post('dashboards/{dashboard}/relations', [DashboardBuilderController::class, 'relations']);
        Route::post('dashboards/{dashboard}/query', [DashboardBuilderController::class, 'query']);

        Route::post('dashboards/{dashboard}/widgets', [DashboardWidgetController::class, 'store']);
        Route::match(['put', 'patch'], 'dashboards/{dashboard}/widgets/{widget}', [DashboardWidgetController::class, 'update']);
        Route::delete('dashboards/{dashboard}/widgets/{widget}', [DashboardWidgetController::class, 'destroy']);
        Route::put('dashboards/{dashboard}/reorder', [DashboardWidgetController::class, 'reorder']);

        Route::middleware('permission:write widget code')->group(function () {

            Route::post('dashboards/{dashboard}/widgets/{widget}/query/run', [DashboardWidgetController::class, 'runQuery']);

            Route::post('dashboards/{dashboard}/widgets/{widget}/query/compose', [DashboardWidgetController::class, 'composeQuery']);
            Route::put('dashboards/{dashboard}/widgets/{widget}/query', [DashboardWidgetController::class, 'saveQuery']);

            Route::post('dashboards/{dashboard}/widgets/{widget}/run', [DashboardWidgetController::class, 'runDraft']);
            Route::put('dashboards/{dashboard}/widgets/{widget}/code', [DashboardWidgetController::class, 'saveCode']);
            Route::post('dashboards/{dashboard}/widgets/{widget}/code/restore', [DashboardWidgetController::class, 'restoreCode']);
        });
    });

    Route::get('messages', [MessageController::class, 'index'])->middleware('permission:view chats');
    Route::get('messages/{message}', [MessageController::class, 'show'])->middleware('permission:view chats');
    Route::post('messages', [MessageController::class, 'store'])->middleware('permission:create chats');

    Route::get('exports', [ExportController::class, 'index'])->middleware('permission:view chats');

    Route::post('get-widget-content/{id}', [WidgetRunController::class, 'run'])
        ->middleware('permission:view dashboards');

    Route::get('widgets/catalog', [WidgetCatalogController::class, 'index'])
        ->middleware('permission:view dashboards');

    Route::prefix('data_source')
        ->name('data_source.')
        ->group(function () {

            Route::get('types', [DataSourceTypeController::class, 'index'])
                ->middleware('permission:view data sources');

            Route::get('/', [DataSourceController::class, 'index'])
                ->middleware('permission:view data sources');
            Route::get('{id}', [DataSourceController::class, 'show'])
                ->middleware('permission:view data sources')
                ->whereNumber('id');

            Route::middleware('permission:manage data sources')->group(function () {
                Route::post('/', [DataSourceController::class, 'store']);

                Route::get('{id}/tables', [DataSourceController::class, 'tables'])
                    ->whereNumber('id');

                Route::post('{id}/refresh', [DataSourceController::class, 'refresh'])
                    ->whereNumber('id');

                Route::post('{id}/grouping', [DataSourceController::class, 'group'])
                    ->whereNumber('id');
                Route::get('{id}/grouping', [DataSourceController::class, 'groupingStatus'])
                    ->whereNumber('id');

                Route::match(['put', 'patch'], '{id}', [DataSourceController::class, 'update'])
                    ->whereNumber('id');
                Route::delete('{id}', [DataSourceController::class, 'destroy'])
                    ->whereNumber('id');
            });

            Route::post('{id}/connection', [DataSourceConnectionController::class, 'query'])
                ->middleware('permission:manage data sources')
                ->name('connection.query');
        });

    Route::get('usage', [\App\Http\Controllers\Company\UsageController::class, 'show']);
    Route::match(['put', 'patch'], 'usage', [\App\Http\Controllers\Company\UsageController::class, 'update'])
        ->middleware('permission:manage company');

    Route::prefix('settings')->group(function () {

        Route::get('users', [\App\Http\Controllers\Company\UsersController::class, 'index'])
            ->middleware('permission:view users');
        Route::get('users/{user}', [\App\Http\Controllers\Company\UsersController::class, 'show'])
            ->middleware('permission:view users');

        Route::middleware('permission:manage users')->group(function () {
            Route::post('users', [\App\Http\Controllers\Company\UsersController::class, 'store']);
            Route::match(['put', 'patch'], 'users/{user}', [\App\Http\Controllers\Company\UsersController::class, 'update']);
            Route::delete('users/{user}', [\App\Http\Controllers\Company\UsersController::class, 'destroy']);
        });

        Route::post('/profile', [\App\Http\Controllers\Company\ProfileController::class, 'update']);
    });

});

Route::middleware(['auth:sanctum', 'active', 'permission:manage data sources'])
    ->post('/upload', [FileController::class, 'store']);
