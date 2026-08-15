<?php

use App\Models\AiChat;
use App\Models\Company;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use App\Models\DataSourceType;
use App\Models\User;
use App\Models\Widget;
use Database\Seeders\DashboardStatusesSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\Widgets\BarChartSeeder;
use Database\Seeders\Widgets\PieChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
