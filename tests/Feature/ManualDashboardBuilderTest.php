<?php

use App\Models\AiChat;
use App\Models\Company;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use App\Models\DataSourceType;
use App\Models\User;
use App\Models\Widget;
use App\Models\Workspace;
use Database\Seeders\DashboardStatusesSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\Widgets\BarChartSeeder;
use Database\Seeders\Widgets\PieChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * Ручная сборка дашборда: создание без чата, добавление виджетов и правка их
 * кода. Отдельно закрыт регресс сгенерированных дашбордов — источник данных
 * у них по-прежнему приходит из чата.
 */

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(PieChartSeeder::class);
    $this->seed(BarChartSeeder::class);
    $this->seed(DashboardStatusesSeeder::class);

    DataSourceType::query()->firstOrCreate(['name' => 'mysql']);
});

function makeBuilderCompany(string $name = 'Acme', string $role = 'company_admin'): array
{
    $company = Company::query()->create(['name' => $name]);

    $user = User::query()->create([
        'name' => $name . ' user',
        'email' => strtolower($name) . '-' . uniqid() . '@example.com',
        'password' => Hash::make('secret123'),
        'company_id' => $company->id,
        'is_active' => true,
    ]);

    $company->owner_id = $user->id;
    $company->save();

    $user->assignRole($role);

    return [$company, $user->fresh()];
}

function makeBuilderSource(Company $company, User $creator): DataSource
{
    return DataSource::query()->create([
        'company_id' => $company->id,
        'created_by' => $creator->id,
        'type_id' => DataSourceType::query()->where('name', 'mysql')->value('id'),
        'connection_type' => 'remote',
        'name' => 'Продажи',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'sales',
        'username' => 'root',
        'password' => 'secret',
    ]);
}

/** Дашборд, собранный руками, вместе с его источником. */
function makeManualDashboard(Company $company, User $user, DataSource $source): Dashboard
{
    return Dashboard::query()->create([
        'company_id' => $company->id,
        'created_by' => $user->id,
        'data_source_id' => $source->id,
        'name' => 'Ручной дашборд',
        'status' => 'empty',
        'origin' => Dashboard::ORIGIN_MANUAL,
    ]);
}

// ---------------------------------------------------------------------------
// Создание
// ---------------------------------------------------------------------------

it('создаёт дашборд без чата, но с источником данных', function () {
    [$company, $user] = makeBuilderCompany();
    $source = makeBuilderSource($company, $user);

    $response = $this->actingAs($user)
        ->postJson('/api/company/dashboards', [
            'name' => 'Продажи по регионам',
            'description' => 'Для еженедельной планёрки',
            'data_source_id' => $source->id,
        ])
        ->assertCreated()
        ->json();

    expect($response['name'])->toBe('Продажи по регионам')
        ->and($response['data_source_id'])->toBe($source->id)
        ->and($response['chat_id'])->toBeNull()
        ->and($response['origin'])->toBe(Dashboard::ORIGIN_MANUAL)
        ->and($response['created_by'])->toBe($user->id)
        ->and($response['status'])->toBe('empty');
});

it('не создаёт дашборд без источника и без чата', function () {
    [$company, $user] = makeBuilderCompany();

    $this->actingAs($user)
        ->postJson('/api/company/dashboards', ['name' => 'Без источника'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('data_source_id');
});

it('не принимает источник чужой компании', function () {
    [, $stranger] = makeBuilderCompany('Stranger');
    [$company, $user] = makeBuilderCompany('Acme');

    $foreignSource = makeBuilderSource($stranger->company, $stranger);

    $this->actingAs($user)
        ->postJson('/api/company/dashboards', [
            'name' => 'Чужой источник',
            'data_source_id' => $foreignSource->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('data_source_id');
});

it('не даёт создать дашборд роли viewer', function () {
    [$company, $viewer] = makeBuilderCompany('Acme', 'viewer');
    $source = makeBuilderSource($company, $viewer);

    $this->actingAs($viewer)
        ->postJson('/api/company/dashboards', [
            'name' => 'Нельзя',
            'data_source_id' => $source->id,
        ])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Виджеты
// ---------------------------------------------------------------------------

it('добавляет виджет и переводит дашборд из пустого в готовый', function () {
    [$company, $user] = makeBuilderCompany();
    $source = makeBuilderSource($company, $user);
    $dashboard = makeManualDashboard($company, $user, $source);

    $bar = Widget::query()->where('name', 'bar')->with('types')->firstOrFail();

    $widget = $this->actingAs($user)
        ->postJson("/api/company/dashboards/{$dashboard->id}/widgets", [
            'widget_id' => $bar->id,
            'title' => 'Выручка по странам',
        ])
        ->assertCreated()
        ->json();

    expect($widget['status'])->toBe('draft')
        ->and($widget['origin'])->toBe(DashboardWidget::ORIGIN_MANUAL)
        ->and($widget['code'])->toBeNull()
        // Тип не передавали — должен подставиться тип семейства по умолчанию.
        ->and($widget['widget_type_id'])->toBe($bar->defaultType()->id);

    expect($dashboard->fresh()->status)->toBe('completed');
});

it('не даёт поставить виджету тип чужого семейства', function () {
    [$company, $user] = makeBuilderCompany();
    $source = makeBuilderSource($company, $user);
    $dashboard = makeManualDashboard($company, $user, $source);

    $bar = Widget::query()->where('name', 'bar')->with('types')->firstOrFail();
    $pie = Widget::query()->where('name', 'pie')->with('types')->firstOrFail();

    $widgetId = $this->actingAs($user)
        ->postJson("/api/company/dashboards/{$dashboard->id}/widgets", [
            'widget_id' => $bar->id,
            'title' => 'Выручка',
        ])
        ->json('id');

    $this->actingAs($user)
        ->patchJson("/api/company/dashboards/{$dashboard->id}/widgets/{$widgetId}", [
            'widget_type_id' => $pie->defaultType()->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('widget_type_id');
});

it('не пускает к виджетам дашборда чужой компании', function () {
    [$otherCompany, $stranger] = makeBuilderCompany('Stranger');
    $otherSource = makeBuilderSource($otherCompany, $stranger);
    $foreignDashboard = makeManualDashboard($otherCompany, $stranger, $otherSource);

    [, $user] = makeBuilderCompany('Acme');

    $bar = Widget::query()->where('name', 'bar')->firstOrFail();

    $this->actingAs($user)
        ->postJson("/api/company/dashboards/{$foreignDashboard->id}/widgets", [
            'widget_id' => $bar->id,
            'title' => 'Чужой',
        ])
        ->assertNotFound();

    $this->actingAs($user)
        ->getJson("/api/company/dashboards/{$foreignDashboard->id}/edit")
        ->assertNotFound();
});

it('переставляет виджеты и не трогает чужие', function () {
    [$company, $user] = makeBuilderCompany();
    $source = makeBuilderSource($company, $user);
    $dashboard = makeManualDashboard($company, $user, $source);

    [$otherCompany, $stranger] = makeBuilderCompany('Stranger');
    $otherSource = makeBuilderSource($otherCompany, $stranger);
    $foreignDashboard = makeManualDashboard($otherCompany, $stranger, $otherSource);

    $bar = Widget::query()->where('name', 'bar')->firstOrFail();

    $first = $this->actingAs($user)
        ->postJson("/api/company/dashboards/{$dashboard->id}/widgets", [
            'widget_id' => $bar->id, 'title' => 'Первый',
        ])->json('id');

    $second = $this->actingAs($user)
        ->postJson("/api/company/dashboards/{$dashboard->id}/widgets", [
            'widget_id' => $bar->id, 'title' => 'Второй',
        ])->json('id');

    $foreignWidget = DashboardWidget::query()->create([
        'dashboard_id' => $foreignDashboard->id,
        'widget_id' => $bar->id,
        'title' => 'Чужой',
        'instruction' => '',
        'position' => 7,
    ]);

    $this->actingAs($user)
        ->putJson("/api/company/dashboards/{$dashboard->id}/reorder", [
            'widgets' => [
                ['id' => $first, 'position' => 1],
                ['id' => $second, 'position' => 0],
                // Чужой виджет в списке не должен сдвинуться.
                ['id' => $foreignWidget->id, 'position' => 0],
            ],
        ])
        ->assertOk();

    expect(DashboardWidget::query()->find($first)->position)->toBe(1)
        ->and(DashboardWidget::query()->find($second)->position)->toBe(0)
        ->and($foreignWidget->fresh()->position)->toBe(7);
});

it('удаляет виджет и возвращает дашборд в пустое состояние', function () {
    [$company, $user] = makeBuilderCompany();
    $source = makeBuilderSource($company, $user);
    $dashboard = makeManualDashboard($company, $user, $source);

    $bar = Widget::query()->where('name', 'bar')->firstOrFail();

    $widgetId = $this->actingAs($user)
        ->postJson("/api/company/dashboards/{$dashboard->id}/widgets", [
            'widget_id' => $bar->id, 'title' => 'Разовый',
        ])->json('id');

    $this->actingAs($user)
        ->deleteJson("/api/company/dashboards/{$dashboard->id}/widgets/{$widgetId}")
        ->assertOk();

    expect(DashboardWidget::query()->find($widgetId))->toBeNull()
        ->and($dashboard->fresh()->status)->toBe('empty');
});

it('отдаёт конструктору дашборд с кодом виджетов и источником', function () {
    [$company, $user] = makeBuilderCompany();
    $source = makeBuilderSource($company, $user);
    $dashboard = makeManualDashboard($company, $user, $source);

    $bar = Widget::query()->where('name', 'bar')->firstOrFail();

    $widget = DashboardWidget::query()->create([
        'dashboard_id' => $dashboard->id,
        'widget_id' => $bar->id,
        'widget_type_id' => $bar->defaultType()->id,
        'title' => 'Выручка',
        'instruction' => '',
        'position' => 0,
        'status' => 'active',
        'origin' => DashboardWidget::ORIGIN_MANUAL,
        'code' => "def main():\n    print(json.dumps({}))\n",
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/company/dashboards/{$dashboard->id}/edit")
        ->assertOk()
        ->json();

    expect($response['data_source']['id'])->toBe($source->id)
        ->and($response['widgets'])->toHaveCount(1)
        ->and($response['widgets'][0]['code'])->toBe($widget->code)
        // Форма данных семейства нужна редактору как подсказка автору.
        ->and($response['widgets'][0]['widget']['scheme'])->not->toBeNull()
        ->and($response['widgets'][0]['has_previous_code'])->toBeFalse();

    // А обычный показ дашборда код виджета не отдаёт: смотрящему он не нужен.
    $shown = $this->actingAs($user)
        ->getJson("/api/company/dashboards/{$dashboard->id}")
        ->assertOk()
        ->json();

    expect($shown['widgets'][0])->not->toHaveKey('code');
});

it('отдаёт список дашбордов с числом виджетов и источником', function () {
    [$company, $user] = makeBuilderCompany();
    $source = makeBuilderSource($company, $user);
    $bar = Widget::query()->where('name', 'bar')->firstOrFail();

    // Собранный руками: источник указан на самом дашборде.
    $manual = makeManualDashboard($company, $user, $source);

    DashboardWidget::query()->create([
        'dashboard_id' => $manual->id,
        'widget_id' => $bar->id,
        'title' => 'Выручка',
        'instruction' => '',
        'position' => 0,
    ]);

    // Выросший из чата: источник лежит на чате, а не на дашборде.
    $chat = AiChat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'data_source_id' => $source->id,
        'title' => 'Разбор продаж',
    ]);

    $fromChat = Dashboard::query()->create([
        'company_id' => $company->id,
        'chat_id' => $chat->id,
        'name' => 'Из чата',
        'status' => 'completed',
    ]);

    // Дашборд чужой компании в список попасть не должен.
    [$otherCompany, $stranger] = makeBuilderCompany('Stranger');
    makeManualDashboard($otherCompany, $stranger, makeBuilderSource($otherCompany, $stranger));

    $list = $this->actingAs($user)
        ->getJson('/api/company/dashboards')
        ->assertOk()
        ->json();

    expect($list)->toHaveCount(2);

    $byId = collect($list)->keyBy('id');

    expect($byId[$manual->id]['widgets_count'])->toBe(1)
        ->and($byId[$manual->id]['data_source']['name'])->toBe($source->name)
        ->and($byId[$manual->id]['origin'])->toBe(Dashboard::ORIGIN_MANUAL)
        // Источник дашборда из чата подставляется, хотя на нём самом его нет.
        ->and($byId[$fromChat->id]['data_source_id'])->toBeNull()
        ->and($byId[$fromChat->id]['data_source']['name'])->toBe($source->name)
        ->and($byId[$fromChat->id]['chat']['title'])->toBe('Разбор продаж')
        ->and($byId[$fromChat->id]['widgets_count'])->toBe(0);

    // Детали подключения в списке не место — там только имя источника.
    expect($byId[$manual->id]['data_source'])->not->toHaveKey('host')
        ->and($byId[$manual->id]['data_source'])->not->toHaveKey('username');
});

// ---------------------------------------------------------------------------
// Право на код
// ---------------------------------------------------------------------------

it('не пускает к коду виджета без права write widget code', function () {
    [$company, $admin] = makeBuilderCompany();
    $source = makeBuilderSource($company, $admin);
    $dashboard = makeManualDashboard($company, $admin, $source);

    $bar = Widget::query()->where('name', 'bar')->firstOrFail();

    $widgetId = $this->actingAs($admin)
        ->postJson("/api/company/dashboards/{$dashboard->id}/widgets", [
            'widget_id' => $bar->id, 'title' => 'Выручка',
        ])->json('id');

    // Сотрудник той же компании, которому оставили правку дашбордов, но не
    // выдали право писать код.
    $editor = User::query()->create([
        'name' => 'Редактор',
        'email' => 'editor-' . uniqid() . '@example.com',
        'password' => Hash::make('secret123'),
        'company_id' => $company->id,
        'is_active' => true,
    ]);
    $editor->givePermissionTo(['view dashboards', 'edit dashboards']);

    $this->actingAs($editor->fresh())
        ->putJson("/api/company/dashboards/{$dashboard->id}/widgets/{$widgetId}/code", [
            'code' => "def main():\n    print(json.dumps({}))\n",
        ])
        ->assertForbidden();

    $this->actingAs($editor->fresh())
        ->postJson("/api/company/dashboards/{$dashboard->id}/widgets/{$widgetId}/run", [
            'code' => "def main():\n    print(json.dumps({}))\n",
        ])
        ->assertForbidden();

    // А переставить виджеты тот же сотрудник по-прежнему может.
    $this->actingAs($editor->fresh())
        ->putJson("/api/company/dashboards/{$dashboard->id}/reorder", [
            'widgets' => [['id' => $widgetId, 'position' => 3]],
        ])
        ->assertOk();
});

// ---------------------------------------------------------------------------
// Рабочие пространства
// ---------------------------------------------------------------------------

/** Пространство вместе с его источником. */
function makeWorkspace(Company $company, User $user, DataSource $source, string $name = 'Продажи'): Workspace
{
    return Workspace::query()->create([
        'company_id' => $company->id,
        'created_by' => $user->id,
        'data_source_id' => $source->id,
        'name' => $name,
    ]);
}

it('заводит пространство и дашборд внутри него', function () {
    [$company, $user] = makeBuilderCompany();
    $source = makeBuilderSource($company, $user);

    $workspace = $this->actingAs($user)
        ->postJson('/api/company/workspaces', [
            'name' => 'Продажи',
            'description' => 'Для еженедельной планёрки',
            'data_source_id' => $source->id,
        ])
        ->assertCreated()
        ->json();

    $dashboard = $this->actingAs($user)
        ->postJson('/api/company/dashboards', [
            'name' => 'Выручка по регионам',
            'workspace_id' => $workspace['id'],
        ])
        ->assertCreated()
        ->json();

    // Источник наследуется от пространства: свой у соседнего дашборда означал бы,
    // что это уже другая задача.
    expect($dashboard['workspace_id'])->toBe($workspace['id'])
        ->and($dashboard['data_source_id'])->toBe($source->id)
        ->and($dashboard['origin'])->toBe(Dashboard::ORIGIN_MANUAL);
});

it('показывает в пространстве только его дашборды', function () {
    [$company, $user] = makeBuilderCompany();
    $source = makeBuilderSource($company, $user);

    $workspace = makeWorkspace($company, $user, $source);

    $manual = Dashboard::query()->create([
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'data_source_id' => $source->id,
        'name' => 'Собран руками',
        'status' => 'empty',
        'origin' => Dashboard::ORIGIN_MANUAL,
    ]);

    // Собран агентом — в том же пространстве, рядом с ручным.
    $chat = AiChat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'data_source_id' => $source->id,
        'title' => 'Разговор',
    ]);

    $generated = Dashboard::query()->create([
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'chat_id' => $chat->id,
        'name' => 'Собран агентом',
        'status' => 'completed',
    ]);

    // Соседнее пространство на том же источнике — это другая задача,
    // и попадать сюда его дашборды не должны.
    $other = makeWorkspace($company, $user, $source, 'Склад');

    $foreign = Dashboard::query()->create([
        'company_id' => $company->id,
        'workspace_id' => $other->id,
        'data_source_id' => $source->id,
        'name' => 'Чужой',
        'status' => 'empty',
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/company/workspaces/{$workspace->id}")
        ->assertOk()
        ->json();

    $ids = collect($response['dashboards'])->pluck('id');

    expect($response['workspace']['name'])->toBe('Продажи')
        ->and($response['data_source']['id'])->toBe($source->id)
        ->and($ids)->toContain($manual->id)
        ->and($ids)->toContain($generated->id)
        ->and($ids)->not->toContain($foreign->id)
        // Новые сверху: перегенерация создаёт следующую версию дашборда,
        // и открывать надо именно её.
        ->and($response['current_dashboard_id'])->toBe($generated->id)
        ->and($response['chat']['id'])->toBe($chat->id);
});

it('находит пространство по дашборду и по чату', function () {
    [$company, $user] = makeBuilderCompany();
    $source = makeBuilderSource($company, $user);
    $workspace = makeWorkspace($company, $user, $source);

    $chat = AiChat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'data_source_id' => $source->id,
        'title' => 'Разговор',
    ]);

    $dashboard = Dashboard::query()->create([
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'chat_id' => $chat->id,
        'name' => 'Продажи',
        'status' => 'completed',
    ]);

    // Ссылка знает только дашборд — этого достаточно, чтобы открыть работу целиком.
    $byDashboard = $this->actingAs($user)
        ->getJson("/api/company/workspaces/by-dashboard/{$dashboard->id}")
        ->assertOk()
        ->json();

    $byChat = $this->actingAs($user)
        ->getJson("/api/company/workspaces/by-chat/{$chat->id}")
        ->assertOk()
        ->json();

    expect($byDashboard['workspace']['id'])->toBe($workspace->id)
        ->and($byDashboard['current_dashboard_id'])->toBe($dashboard->id)
        ->and($byChat['workspace']['id'])->toBe($workspace->id);
});

it('заводит пространству разговор — один на всю работу', function () {
    [$company, $user] = makeBuilderCompany();
    $source = makeBuilderSource($company, $user);
    $workspace = makeWorkspace($company, $user, $source);

    $created = $this->actingAs($user)
        ->postJson("/api/company/workspaces/{$workspace->id}/chat")
        ->assertCreated()
        ->json();

    $chat = AiChat::query()->find($created['chat']['id']);

    expect($chat->workspace_id)->toBe($workspace->id)
        ->and($chat->data_source_id)->toBe($source->id);

    // Повторное нажатие не плодит разговоры: он один на задачу.
    $again = $this->actingAs($user)
        ->postJson("/api/company/workspaces/{$workspace->id}/chat")
        ->assertOk()
        ->json();

    expect($again['chat']['id'])->toBe($created['chat']['id'])
        ->and(AiChat::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('удаляет пространство вместе с дашбордами и перепиской, но не с источником', function () {
    [$company, $user] = makeBuilderCompany();
    $source = makeBuilderSource($company, $user);
    $workspace = makeWorkspace($company, $user, $source);

    $chat = AiChat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'data_source_id' => $source->id,
        'title' => 'Разговор',
    ]);

    $dashboard = Dashboard::query()->create([
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'name' => 'Продажи',
        'status' => 'empty',
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/company/workspaces/{$workspace->id}")
        ->assertOk();

    expect(Workspace::query()->find($workspace->id))->toBeNull()
        ->and(Dashboard::query()->find($dashboard->id))->toBeNull()
        ->and(AiChat::query()->find($chat->id))->toBeNull()
        // Источник принадлежит компании, а не пространству: на нём работают другие.
        ->and(DataSource::query()->find($source->id))->not->toBeNull();
});

it('не отдаёт пространство чужой компании', function () {
    [$company, $owner] = makeBuilderCompany('Acme');
    $source = makeBuilderSource($company, $owner);
    $workspace = makeWorkspace($company, $owner, $source);

    [, $stranger] = makeBuilderCompany('Stranger');

    $this->actingAs($stranger)
        ->getJson("/api/company/workspaces/{$workspace->id}")
        ->assertNotFound();

    $this->actingAs($stranger)
        ->deleteJson("/api/company/workspaces/{$workspace->id}")
        ->assertNotFound();
});


// ---------------------------------------------------------------------------
// Настройки конструктора доезжают до сборщика запроса
// ---------------------------------------------------------------------------

it('доносит связи, таблицы метрик и цели до сборщика запроса', function () {
    [$company, $user] = makeBuilderCompany();
    $source = makeBuilderSource($company, $user);
    $dashboard = makeManualDashboard($company, $user, $source);

    $bar = Widget::query()->where('name', 'bar')->with('types')->firstOrFail();

    $widget = DashboardWidget::query()->create([
        'dashboard_id' => $dashboard->id,
        'widget_id' => $bar->id,
        'widget_type_id' => $bar->defaultType()?->id,
        'title' => 'Выручка по городам',
        'instruction' => '',
        'position' => 0,
        'status' => 'draft',
        'origin' => DashboardWidget::ORIGIN_MANUAL,
    ]);

    Cache::put("datasource:{$source->id}:schema", [
        [
            'name' => 'orders',
            'columns' => [
                ['name' => 'amount', 'type' => 'decimal(10,2)', 'kind' => 'number'],
                ['name' => 'customer_id', 'type' => 'int', 'kind' => 'number'],
            ],
        ],
        [
            'name' => 'customers',
            'columns' => [
                ['name' => 'id', 'type' => 'int', 'kind' => 'number'],
                ['name' => 'city', 'type' => 'varchar(64)', 'kind' => 'string'],
            ],
        ],
    ], now()->addMinutes(5));

    // Сборка без выполнения: запрос к базе клиента здесь не идёт, поэтому
    // проверить можно ровно то, что нужно, — что настройки доехали целиком.
    $response = $this->actingAs($user)
        ->postJson("/api/company/dashboards/{$dashboard->id}/widgets/{$widget->id}/query/compose", [
            'builder' => [
                'table' => 'orders',
                'joins' => [[
                    'table' => 'customers',
                    'type' => 'left',
                    'on' => [[
                        'left_table' => 'orders',
                        'left' => 'customer_id',
                        'right' => 'id',
                    ]],
                ]],
                'metrics' => [[
                    'agg' => 'sum',
                    'column' => 'amount',
                    'table' => 'orders',
                    'label' => 'Выручка',
                    'target' => 1000,
                ]],
                'dimensions' => [['column' => 'city', 'table' => 'customers']],
                'filters' => [['column' => 'city', 'table' => 'customers', 'op' => '=', 'value' => 'Москва']],
            ],
        ])
        ->assertOk()
        ->json();

    // Всё, чего нет в правилах validate(), по дороге отбрасывается: раньше
    // связи и таблицы колонок исчезали молча, и виджет считался по одной
    // таблице вместо двух.
    expect($response['sql'])->toContain('LEFT JOIN `customers`')
        ->toContain('`orders`.`customer_id` = `customers`.`id`')
        ->toContain('`customers`.`city`')
        ->toContain('SUM(`orders`.`amount`)');
});

// ---------------------------------------------------------------------------
// Регресс генерации
// ---------------------------------------------------------------------------

it('по-прежнему находит источник сгенерированного дашборда через чат', function () {
    [$company, $user] = makeBuilderCompany();
    $source = makeBuilderSource($company, $user);

    $chat = AiChat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'data_source_id' => $source->id,
    ]);

    // Ровно то, что создаёт DashboardGenerator: чат есть, data_source_id пуст.
    $dashboard = Dashboard::query()->create([
        'company_id' => $company->id,
        'chat_id' => $chat->id,
        'status' => 'completed',
    ]);

    expect($dashboard->data_source_id)->toBeNull()
        ->and($dashboard->origin)->toBe(Dashboard::ORIGIN_AI)
        ->and($dashboard->resolveDataSource()?->id)->toBe($source->id);
});

it('предпочитает источник дашборда источнику чата', function () {
    [$company, $user] = makeBuilderCompany();
    $chatSource = makeBuilderSource($company, $user);
    $ownSource = makeBuilderSource($company, $user);

    $chat = AiChat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'data_source_id' => $chatSource->id,
    ]);

    $dashboard = Dashboard::query()->create([
        'company_id' => $company->id,
        'chat_id' => $chat->id,
        'data_source_id' => $ownSource->id,
        'status' => 'completed',
    ]);

    expect($dashboard->resolveDataSource()?->id)->toBe($ownSource->id);
});
