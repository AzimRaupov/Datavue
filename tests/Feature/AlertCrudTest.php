<?php

use App\Models\Alert;
use App\Models\Company;
use App\Models\DataSource;
use App\Models\DataSourceType;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * CRUD алертов: изоляция по компании и разделение прав между 'manage alerts'
 * (конструктор метрик) и 'write alert code' (SQL/Python выполняются на
 * сервере — как и у ручных виджетов, это отдельное право).
 *
 * Условие в этих тестах — режим sql: структурная проверка при сохранении
 * (ReadOnlySqlGuard) не обращается к базе клиента, поэтому тесты не требуют
 * настоящего подключения к источнику.
 */

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    DataSourceType::query()->firstOrCreate(['name' => 'mysql']);
});

function makeAlertCompany(string $name = 'Acme', string $role = 'company_admin'): array
{
    $company = Company::query()->create(['name' => $name]);

    $user = User::query()->create([
        'name' => $name.' user',
        'email' => strtolower($name).'-'.uniqid().'@example.com',
        'password' => Hash::make('secret123'),
        'company_id' => $company->id,
        'is_active' => true,
    ]);

    $company->owner_id = $user->id;
    $company->save();

    if ($role) {
        $user->assignRole($role);
    }

    return [$company, $user->fresh()];
}

function makeAlertWorkspace(Company $company, User $creator): Workspace
{
    $source = DataSource::query()->create([
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

    return Workspace::query()->create([
        'company_id' => $company->id,
        'created_by' => $creator->id,
        'data_source_id' => $source->id,
        'name' => 'Склад',
    ]);
}

function alertPayload(array $overrides = []): array
{
    return array_replace([
        'title' => 'Мало заказов',
        'mode' => Alert::MODE_SQL,
        'query' => 'SELECT id FROM orders',
        'condition' => ['kind' => 'rows', 'op' => '>', 'threshold' => 0, 'on_empty' => 'ok'],
        'interval_minutes' => 60,
        'recipients' => ['emails' => ['ops@example.com']],
    ], $overrides);
}

it('создаёт алерт в режиме sql', function () {
    [$company, $user] = makeAlertCompany();
    $workspace = makeAlertWorkspace($company, $user);

    $response = $this->actingAs($user)
        ->postJson("/api/company/workspaces/{$workspace->id}/alerts", alertPayload())
        ->assertCreated()
        ->json();

    expect($response['mode'])->toBe('sql')
        ->and($response['state'])->toBe(Alert::STATE_UNKNOWN)
        ->and($response['is_active'])->toBeTrue()
        ->and($response['recipients']['emails'])->toBe(['ops@example.com']);

    $this->assertDatabaseHas('alerts', [
        'id' => $response['id'],
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'data_source_id' => $workspace->data_source_id,
    ]);
});

it('не создаёт алерт без источника данных у пространства', function () {
    [$company, $user] = makeAlertCompany();

    $workspace = Workspace::query()->create([
        'company_id' => $company->id,
        'created_by' => $user->id,
        'name' => 'Без источника',
    ]);

    $this->actingAs($user)
        ->postJson("/api/company/workspaces/{$workspace->id}/alerts", alertPayload())
        ->assertStatus(422);
});

it('отклоняет синтаксически некорректный SQL до сохранения', function () {
    [$company, $user] = makeAlertCompany();
    $workspace = makeAlertWorkspace($company, $user);

    $this->actingAs($user)
        ->postJson("/api/company/workspaces/{$workspace->id}/alerts", alertPayload([
            'query' => 'UPDATE orders SET status = 1',
        ]))
        ->assertStatus(422);

    $this->assertDatabaseCount('alerts', 0);
});

it('требует получателя', function () {
    [$company, $user] = makeAlertCompany();
    $workspace = makeAlertWorkspace($company, $user);

    $this->actingAs($user)
        ->postJson("/api/company/workspaces/{$workspace->id}/alerts", alertPayload([
            'recipients' => ['emails' => []],
        ]))
        ->assertStatus(422);
});

it('не принимает сотрудника чужой компании в получатели', function () {
    [$company, $user] = makeAlertCompany('Acme');
    [, $stranger] = makeAlertCompany('Stranger');
    $workspace = makeAlertWorkspace($company, $user);

    $this->actingAs($user)
        ->postJson("/api/company/workspaces/{$workspace->id}/alerts", alertPayload([
            'recipients' => ['users' => [$stranger->id]],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('recipients.users.0');
});

it('не даёт viewer создавать алерты, но даёт смотреть', function () {
    [$company, $admin] = makeAlertCompany('Acme');
    $workspace = makeAlertWorkspace($company, $admin);

    $alert = Alert::query()->create(alertPayload() + [
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'data_source_id' => $workspace->data_source_id,
        'created_by' => $admin->id,
    ]);

    [, $viewer] = makeAlertCompany('Acme2', 'viewer');
    $viewer->company_id = $company->id;
    $viewer->save();

    $this->actingAs($viewer)
        ->postJson("/api/company/workspaces/{$workspace->id}/alerts", alertPayload())
        ->assertForbidden();

    $this->actingAs($viewer)
        ->getJson("/api/company/alerts/{$alert->id}")
        ->assertOk();
});

it('требует право write alert code для sql, но не для builder', function () {
    [$company, $admin] = makeAlertCompany('Acme');
    $workspace = makeAlertWorkspace($company, $admin);

    $limited = User::query()->create([
        'name' => 'Без кода',
        'email' => 'limited-'.uniqid().'@example.com',
        'password' => Hash::make('secret123'),
        'company_id' => $company->id,
        'is_active' => true,
    ]);
    $limited->givePermissionTo(['view alerts', 'manage alerts']);

    $this->actingAs($limited)
        ->postJson("/api/company/workspaces/{$workspace->id}/alerts", alertPayload())
        ->assertForbidden();

    // Режим builder не требует права на код: запрос собирает платформа.
    // Источник в этом тесте ненастоящий, поэтому дальше запрос упрётся
    // в недоступную базу (422) — важно только то, что это не 403.
    $this->actingAs($limited)
        ->postJson("/api/company/workspaces/{$workspace->id}/alerts", alertPayload([
            'mode' => Alert::MODE_BUILDER,
            'builder' => ['table' => 'orders', 'metrics' => [['agg' => 'count']]],
            'query' => null,
        ]))
        ->assertStatus(422);
});

it('не находит алерт чужой компании', function () {
    [$company, $admin] = makeAlertCompany('Acme');
    $workspace = makeAlertWorkspace($company, $admin);

    $alert = Alert::query()->create(alertPayload() + [
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'data_source_id' => $workspace->data_source_id,
        'created_by' => $admin->id,
    ]);

    [, $stranger] = makeAlertCompany('Stranger');

    $this->actingAs($stranger)
        ->getJson("/api/company/alerts/{$alert->id}")
        ->assertNotFound();
});

it('переключает is_active кнопкой toggle', function () {
    [$company, $admin] = makeAlertCompany('Acme');
    $workspace = makeAlertWorkspace($company, $admin);

    $alert = Alert::query()->create(alertPayload() + [
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'data_source_id' => $workspace->data_source_id,
        'created_by' => $admin->id,
        'is_active' => false,
        'disabled_reason' => 'было выключено',
        'consecutive_failures' => 3,
    ]);

    $response = $this->actingAs($admin)
        ->postJson("/api/company/alerts/{$alert->id}/toggle")
        ->assertOk()
        ->json();

    expect($response['is_active'])->toBeTrue()
        ->and($response['disabled_reason'])->toBeNull()
        ->and($response['consecutive_failures'])->toBe(0);
});

it('удаляет алерт вместе с историей', function () {
    [$company, $admin] = makeAlertCompany('Acme');
    $workspace = makeAlertWorkspace($company, $admin);

    $alert = Alert::query()->create(alertPayload() + [
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'data_source_id' => $workspace->data_source_id,
        'created_by' => $admin->id,
    ]);

    $alert->history()->create([
        'checking_at' => now(),
        'status' => 'ok',
        'trigger_source' => 'manual',
    ]);

    $this->actingAs($admin)
        ->deleteJson("/api/company/alerts/{$alert->id}")
        ->assertOk();

    $this->assertDatabaseMissing('alerts', ['id' => $alert->id]);
    $this->assertDatabaseCount('alert_checker_histories', 0);
});

it('удаление рабочего пространства удаляет его алерты', function () {
    [$company, $admin] = makeAlertCompany('Acme');
    $workspace = makeAlertWorkspace($company, $admin);

    $alert = Alert::query()->create(alertPayload() + [
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'data_source_id' => $workspace->data_source_id,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->deleteJson("/api/company/workspaces/{$workspace->id}")
        ->assertOk();

    $this->assertDatabaseMissing('alerts', ['id' => $alert->id]);
});
