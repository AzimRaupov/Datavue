<?php

use App\Models\AiChat;
use App\Models\Company;
use App\Models\Dashboard;
use App\Models\DataSource;
use App\Models\DataSourceType;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DashboardStatusesSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(DashboardStatusesSeeder::class);

    DataSourceType::query()->firstOrCreate(['name' => 'mysql']);

    $company = Company::query()->create(['name' => 'Acme']);

    $this->user = User::query()->create([
        'name' => 'Acme user',
        'email' => 'acme-' . uniqid() . '@example.com',
        'password' => Hash::make('secret123'),
        'company_id' => $company->id,
        'is_active' => true,
    ]);
    $this->user->assignRole('company_admin');

    $source = DataSource::query()->create([
        'company_id' => $company->id,
        'created_by' => $this->user->id,
        'type_id' => DataSourceType::query()->where('name', 'mysql')->value('id'),
        'connection_type' => 'remote',
        'name' => 'Продажи',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'sales',
        'username' => 'root',
        'password' => 'secret',
    ]);

    $this->workspace = Workspace::query()->create([
        'company_id' => $company->id,
        'created_by' => $this->user->id,
        'data_source_id' => $source->id,
        'name' => 'Пространство',
    ]);

    $this->makeDashboard = fn (string $name, ?int $chatId = null) => Dashboard::query()->create([
        'company_id' => $company->id,
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'data_source_id' => $source->id,
        'chat_id' => $chatId,
        'name' => $name,
        'status' => 'empty',
        'origin' => Dashboard::ORIGIN_MANUAL,
    ]);
});

it('заводит разговор дашборда без чата и привязывает его', function () {
    $dashboard = ($this->makeDashboard)('Без чата');

    $this->actingAs($this->user)
        ->getJson("/api/company/workspaces/{$this->workspace->id}?dashboard={$dashboard->id}")
        ->assertOk()
        ->assertJsonPath('chat', null);

    $chatId = $this->actingAs($this->user)
        ->postJson("/api/company/workspaces/{$this->workspace->id}/chat", ['dashboard_id' => $dashboard->id])
        ->assertCreated()
        ->json('chat.id');

    expect($dashboard->fresh()->chat_id)->toBe($chatId);

    $this->actingAs($this->user)
        ->getJson("/api/company/workspaces/{$this->workspace->id}?dashboard={$dashboard->id}")
        ->assertJsonPath('chat.id', $chatId);
});

it('открывает уже существующий разговор дашборда, а не заводит новый', function () {
    $chat = AiChat::query()->create([
        'user_id' => $this->user->id,
        'company_id' => $this->user->company_id,
        'workspace_id' => $this->workspace->id,
        'data_source_id' => $this->workspace->data_source_id,
        'title' => 'Старый',
    ]);
    $dashboard = ($this->makeDashboard)('С чатом', $chat->id);

    $this->actingAs($this->user)
        ->postJson("/api/company/workspaces/{$this->workspace->id}/chat", ['dashboard_id' => $dashboard->id])
        ->assertOk()
        ->assertJsonPath('chat.id', $chat->id);

    expect(AiChat::query()->count())->toBe(1);
});

it('у соседнего дашборда разговор свой, а не общий на пространство', function () {
    $withChat = ($this->makeDashboard)('Первый');
    $other = ($this->makeDashboard)('Второй');

    $this->actingAs($this->user)
        ->postJson("/api/company/workspaces/{$this->workspace->id}/chat", ['dashboard_id' => $withChat->id])
        ->assertCreated();

    $this->actingAs($this->user)
        ->getJson("/api/company/workspaces/{$this->workspace->id}?dashboard={$other->id}")
        ->assertJsonPath('chat', null);

    $this->actingAs($this->user)
        ->postJson("/api/company/workspaces/{$this->workspace->id}/chat", ['dashboard_id' => $other->id])
        ->assertCreated();

    expect(AiChat::query()->count())->toBe(2);
});

it('не принимает дашборд чужого пространства', function () {
    $foreign = Workspace::query()->create([
        'company_id' => $this->user->company_id,
        'created_by' => $this->user->id,
        'data_source_id' => $this->workspace->data_source_id,
        'name' => 'Другое',
    ]);
    $dashboard = ($this->makeDashboard)('Чужой');

    $this->actingAs($this->user)
        ->postJson("/api/company/workspaces/{$foreign->id}/chat", ['dashboard_id' => $dashboard->id])
        ->assertStatus(422);
});

it('на обзоре пространства отдаёт список чатов, а не один общий', function () {
    $dashboard = ($this->makeDashboard)('Первый');

    $this->actingAs($this->user)
        ->postJson("/api/company/workspaces/{$this->workspace->id}/chat", ['dashboard_id' => $dashboard->id])
        ->assertCreated();

    $this->actingAs($this->user)
        ->postJson("/api/company/workspaces/{$this->workspace->id}/chats")
        ->assertCreated()
        ->assertJsonPath('card.dashboards_count', 0);

    $this->actingAs($this->user)
        ->getJson("/api/company/workspaces/{$this->workspace->id}")
        ->assertOk()
        ->assertJsonPath('chat', null)
        ->assertJsonCount(2, 'chats');

    expect($dashboard->fresh()->chat_id)->not->toBeNull();
});
