<?php

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
// 'active' обязателен и здесь: без него отключённый сотрудник загружал бы
// интерфейс целиком и упирался в 403 на каждом действии вместо понятного
// «учётная запись отключена».
Route::post('/get-user', [AuthController::class, 'getUser'])->middleware(['auth:sanctum', 'active']);

// Метод в контроллере был, а маршрута к нему не существовало — выйти из
// аккаунта было невозможно, токен оставался действительным навсегда.
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');


Route::middleware(['auth:sanctum', 'active'])->prefix('company')->group(function () {
    // Права разделены на чтение и изменение: роль viewer видит дашборды и чаты,
    // но не может их создавать, менять или удалять.
    Route::get('chats', [ChatController::class, 'index'])->middleware('permission:view chats');
    Route::get('chats/{chat}', [ChatController::class, 'show'])->middleware('permission:view chats');
    Route::post('chats', [ChatController::class, 'store'])->middleware('permission:create chats');
    Route::match(['put', 'patch'], 'chats/{chat}', [ChatController::class, 'update'])->middleware('permission:edit chats');
    Route::delete('chats/{chat}', [ChatController::class, 'destroy'])->middleware('permission:delete chats');

    /*
    | Рабочие пространства: задача, её дашборды и разговор с агентом.
    |
    | Страница пространства собирается одним запросом, а не набором: она
    | открывается на каждом переключении дашборда, и ходить за одним и тем же
    | четыре раза незачем.
    |
    | Маршруты по дашборду и по чату объявлены ДО '{workspace}', иначе
    | 'by-dashboard' попадёт в параметр id.
    */
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
    // Разговор пространства — заводится по кнопке «AI Ассистент».
    Route::post('workspaces/{workspace}/chat', [WorkspaceController::class, 'attachChat'])
        ->middleware('permission:create chats');

    Route::get('dashboards', [DashboardController::class, 'index'])->middleware('permission:view dashboards');
    Route::get('dashboards/{dashboard}', [DashboardController::class, 'show'])->middleware('permission:view dashboards');
    Route::post('dashboards', [DashboardController::class, 'store'])->middleware('permission:create dashboards');
    Route::match(['put', 'patch'], 'dashboards/{dashboard}', [DashboardController::class, 'update'])->middleware('permission:edit dashboards');
    // Ручная смена типа отрисовки виджетов — пакетно, по кнопке «Сохранить».
    Route::match(['put', 'patch'], 'dashboards/{dashboard}/widgets', [DashboardController::class, 'updateWidgets'])
        ->middleware('permission:edit dashboards');
    Route::delete('dashboards/{dashboard}', [DashboardController::class, 'destroy'])->middleware('permission:delete dashboards');

    /*
    | Рабочее место сборки дашборда.
    |
    | Отделено от просмотра: здесь отдаётся код виджетов и схема источника,
    | которые смотрящему дашборд не нужны. Написание кода закрыто отдельным
    | правом — оно выполняется на сервере, в отличие от перестановки виджетов.
    */
    Route::middleware('permission:edit dashboards')->group(function () {
        Route::get('dashboards/{dashboard}/edit', [DashboardBuilderController::class, 'edit']);
        Route::get('dashboards/{dashboard}/schema', [DashboardBuilderController::class, 'schema']);
        // Связи между таблицами — конструктор предлагает условие соединения.
        Route::post('dashboards/{dashboard}/relations', [DashboardBuilderController::class, 'relations']);
        Route::post('dashboards/{dashboard}/query', [DashboardBuilderController::class, 'query']);

        Route::post('dashboards/{dashboard}/widgets', [DashboardWidgetController::class, 'store']);
        Route::match(['put', 'patch'], 'dashboards/{dashboard}/widgets/{widget}', [DashboardWidgetController::class, 'update']);
        Route::delete('dashboards/{dashboard}/widgets/{widget}', [DashboardWidgetController::class, 'destroy']);
        Route::put('dashboards/{dashboard}/reorder', [DashboardWidgetController::class, 'reorder']);

        Route::middleware('permission:write widget code')->group(function () {
            // Основной способ задать содержимое виджета — SQL-запрос.
            Route::post('dashboards/{dashboard}/widgets/{widget}/query/run', [DashboardWidgetController::class, 'runQuery']);
            // Сборка запроса без выполнения — показать SQL во время настройки.
            Route::post('dashboards/{dashboard}/widgets/{widget}/query/compose', [DashboardWidgetController::class, 'composeQuery']);
            Route::put('dashboards/{dashboard}/widgets/{widget}/query', [DashboardWidgetController::class, 'saveQuery']);

            // Python остался у виджетов, написанных до перехода на запросы.
            Route::post('dashboards/{dashboard}/widgets/{widget}/run', [DashboardWidgetController::class, 'runDraft']);
            Route::put('dashboards/{dashboard}/widgets/{widget}/code', [DashboardWidgetController::class, 'saveCode']);
            Route::post('dashboards/{dashboard}/widgets/{widget}/code/restore', [DashboardWidgetController::class, 'restoreCode']);
        });
    });

    // Сообщения агенту — это работа с чатом, поэтому право на создание чата.
    Route::get('messages', [MessageController::class, 'index'])->middleware('permission:view chats');
    Route::get('messages/{message}', [MessageController::class, 'show'])->middleware('permission:view chats');
    Route::post('messages', [MessageController::class, 'store'])->middleware('permission:create chats');

    // Файлы, выгруженные агентом в этом чате.
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

            // Источники данных компании. Просмотр отделён от изменения:
            // роль viewer видит подключённые базы, но не трогает их.
            Route::get('/', [DataSourceController::class, 'index'])
                ->middleware('permission:view data sources');
            Route::get('{id}', [DataSourceController::class, 'show'])
                ->middleware('permission:view data sources')
                ->whereNumber('id');

            Route::middleware('permission:manage data sources')->group(function () {
                Route::post('/', [DataSourceController::class, 'store']);

                // Шаги мастера подключения источника: показать найденные
                // таблицы и запустить их группировку по смыслу.
                Route::get('{id}/tables', [DataSourceController::class, 'tables'])
                    ->whereNumber('id');
                // Обновление данных источника: перезалив файла или
                // перечитывание Google-таблицы по сохранённой ссылке.
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


    // Расход ИИ видит любой сотрудник — это влияет на его работу.
    // Менять потолок может только управляющий компанией.
    Route::get('usage', [\App\Http\Controllers\Company\UsageController::class, 'show']);
    Route::match(['put', 'patch'], 'usage', [\App\Http\Controllers\Company\UsageController::class, 'update'])
        ->middleware('permission:manage company');

    Route::prefix('settings')->group(function () {
        // Управление сотрудниками доступно только тем, у кого есть на это право
        // (по умолчанию — роль company_admin). Просмотр отделён от изменения.
        Route::get('users', [\App\Http\Controllers\Company\UsersController::class, 'index'])
            ->middleware('permission:view users');
        Route::get('users/{user}', [\App\Http\Controllers\Company\UsersController::class, 'show'])
            ->middleware('permission:view users');

        // Заведение и правка сотрудников. Сборка доступа галочками требует
        // вдобавок права 'manage roles' — оно проверяется в контроллере,
        // потому что зависит от тела запроса (UsersController::authorizeAccessChange).
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
