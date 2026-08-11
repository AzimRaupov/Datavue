<?php

use App\Helpers\Ai\AiUsage;
use App\Helpers\Ai\AiUsageContext;
use App\Helpers\DataSource\DataSourceRefresher;
use App\Models\AiChat;
use App\Models\Company;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use App\Models\DataSourceType;
use App\Models\User;
use App\Models\Widget;
use App\Models\WidgetType;
use Database\Seeders\DashboardStatusesSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\Widgets\BarChartSeeder;
use Database\Seeders\Widgets\PieChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * Новый порядок работы: компания подключает источник данных, а потом заводит
 * на нём сколько угодно чатов. Тесты закрывают именно связку «источник → чат»
 * и изоляцию между компаниями.
 */

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    // WidgetSeeder заводит только семейства; варианты отрисовки (widget_types)
    // создают отдельные сидеры семейств — без них менять тип нечем.
    $this->seed(PieChartSeeder::class);
    $this->seed(BarChartSeeder::class);
    // dashboards.status — внешний ключ на dashboard_statuses.name.
    $this->seed(DashboardStatusesSeeder::class);

    DataSourceType::query()->firstOrCreate(['name' => 'mysql']);
    DataSourceType::query()->firstOrCreate(['name' => 'duckdb']);
});

/** Компания с владельцем-администратором и произвольной ролью для сотрудника. */
function makeCompany(string $name = 'Acme', string $role = 'company_admin'): array
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

    return [$company, $user];
}

function makeSource(Company $company, User $creator, string $name = 'Продажи'): DataSource
{
    return DataSource::query()->create([
        'company_id' => $company->id,
        'created_by' => $creator->id,
        'type_id' => DataSourceType::query()->where('name', 'mysql')->value('id'),
        'connection_type' => 'remote',
        'name' => $name,
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'sales',
        'username' => 'root',
        'password' => 'secret',
    ]);
}

it('заводит несколько чатов на одном источнике', function () {
    [$company, $user] = makeCompany();
    $source = makeSource($company, $user);

    $first = $this->actingAs($user)
        ->postJson('/api/company/chats', [
            'data_source_id' => $source->id,
            'title' => 'Выручка по месяцам',
        ])
        ->assertCreated()
        ->json('chat');

    $second = $this->actingAs($user)
        ->postJson('/api/company/chats', ['data_source_id' => $source->id])
        ->assertCreated()
        ->json('chat');

    expect($first['data_source_id'])->toBe($source->id)
        ->and($second['data_source_id'])->toBe($source->id)
        ->and($first['title'])->toBe('Выручка по месяцам')
        // Без названия чат всё равно получает осмысленный заголовок.
        ->and($second['title'])->toContain($source->name);

    expect($source->chats()->count())->toBe(2);
});

it('находит источник чата через resolveDataSource', function () {
    [$company, $user] = makeCompany();
    $source = makeSource($company, $user);

    $chat = AiChat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'data_source_id' => $source->id,
    ]);

    expect($chat->resolveDataSource()?->id)->toBe($source->id);
});

it('оставляет источник живым после удаления чата', function () {
    [$company, $user] = makeCompany();
    $source = makeSource($company, $user);

    $chatId = $this->actingAs($user)
        ->postJson('/api/company/chats', ['data_source_id' => $source->id])
        ->json('chat.id');

    $this->actingAs($user)
        ->deleteJson("/api/company/chats/{$chatId}")
        ->assertOk();

    // Именно это ломалось раньше: удаление чата уносило с собой базу.
    expect(DataSource::query()->find($source->id))->not->toBeNull()
        ->and(AiChat::query()->find($chatId))->toBeNull();
});

it('удаляет источник вместе с его чатами', function () {
    [$company, $user] = makeCompany();
    $source = makeSource($company, $user);

    $chatId = $this->actingAs($user)
        ->postJson('/api/company/chats', ['data_source_id' => $source->id])
        ->json('chat.id');

    $this->actingAs($user)
        ->deleteJson("/api/company/data_source/{$source->id}")
        ->assertOk();

    expect(DataSource::query()->find($source->id))->toBeNull()
        ->and(AiChat::query()->find($chatId))->toBeNull();
});

it('не отдаёт пароль источника наружу', function () {
    [$company, $user] = makeCompany();
    makeSource($company, $user);

    $response = $this->actingAs($user)->getJson('/api/company/data_source');

    $response->assertOk();
    expect($response->json())->toHaveCount(1);
    $response->assertJsonMissing(['password' => 'secret']);
    expect(array_key_exists('password', $response->json()[0]))->toBeFalse();
});

it('не даёт завести чат на источнике чужой компании', function () {
    [, $stranger] = makeCompany('Other');
    [$company, $user] = makeCompany('Acme');
    $source = makeSource($company, $user);

    // Источник существует, но принадлежит другой компании — 404, а не 403:
    // чужие источники не должны раскрываться даже фактом существования.
    $this->actingAs($stranger)
        ->postJson('/api/company/chats', ['data_source_id' => $source->id])
        ->assertNotFound();

    $this->actingAs($stranger)
        ->getJson("/api/company/data_source/{$source->id}")
        ->assertNotFound();
});

it('показывает источники наблюдателю, но не даёт их менять', function () {
    [$company, $admin] = makeCompany('Acme');
    $source = makeSource($company, $admin);

    $viewer = User::query()->create([
        'name' => 'Наблюдатель',
        'email' => 'viewer-' . uniqid() . '@example.com',
        'password' => Hash::make('secret123'),
        'company_id' => $company->id,
        'is_active' => true,
    ]);
    $viewer->assignRole('viewer');

    $this->actingAs($viewer)
        ->getJson('/api/company/data_source')
        ->assertOk();

    $this->actingAs($viewer)
        ->putJson("/api/company/data_source/{$source->id}", ['name' => 'Переименован'])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->deleteJson("/api/company/data_source/{$source->id}")
        ->assertForbidden();

    // Роль viewer не создаёт и чаты.
    $this->actingAs($viewer)
        ->postJson('/api/company/chats', ['data_source_id' => $source->id])
        ->assertForbidden();
});

it('находит источник виджета через дашборд и чат', function () {
    [$company, $user] = makeCompany();
    $source = makeSource($company, $user);

    $chat = AiChat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'data_source_id' => $source->id,
    ]);

    $dashboard = Dashboard::query()->create([
        'company_id' => $company->id,
        'chat_id' => $chat->id,
        'name' => 'Продажи',
        'status' => 'completed',
    ]);

    $widget = DashboardWidget::query()->create([
        'dashboard_id' => $dashboard->id,
        'widget_id' => Widget::query()->value('id'),
        'instruction' => 'Показать общее число клиентов',
        'title' => 'Счётчики',
        'position' => 0,
        'status' => 'active',
    ]);

    // Регрессия: источник искался как DataSource::where('chat_id', ...), эта
    // связь больше не заполняется — firstOrFail() валился на каждом виджете,
    // и дашборд оставался пустым при успешной генерации.
    // Кода у виджета нет, поэтому ждём ответ 'pending', а НЕ 404/422.
    $this->actingAs($user)
        ->postJson("/api/company/get-widget-content/{$widget->id}", ['chat_id' => $chat->id])
        ->assertOk()
        ->assertJson(['pending' => true]);

    // chat_id из запроса больше не влияет на выбор источника: даже мусорное
    // значение не мешает найти источник через сам виджет.
    $this->actingAs($user)
        ->postJson("/api/company/get-widget-content/{$widget->id}", ['chat_id' => 999999])
        ->assertOk()
        ->assertJson(['pending' => true]);
});

it('отзывает токен при выходе из аккаунта', function () {
    [, $user] = makeCompany();

    $token = $user->createToken('test')->plainTextToken;
    $auth = ['Authorization' => 'Bearer ' . $token];

    $this->withHeaders($auth)->postJson('/api/get-user')->assertOk();

    $this->withHeaders($auth)->postJson('/api/logout')->assertOk();

    // Регрессия: маршрута /logout не существовало вовсе, токен жил вечно.
    expect($user->tokens()->count())->toBe(0);

    // Гвард кэширует пользователя в рамках одного экземпляра приложения,
    // а в тестах все запросы идут через него же — без сброса третий запрос
    // вернул бы того же пользователя, не заглядывая в токен.
    $this->app['auth']->forgetGuards();

    $this->withHeaders($auth)->postJson('/api/get-user')->assertUnauthorized();
});

it('считает расход токенов и отклоняет запросы при исчерпанном лимите', function () {
    [$company, $user] = makeCompany();
    $source = makeSource($company, $user);

    $chatId = $this->actingAs($user)
        ->postJson('/api/company/chats', ['data_source_id' => $source->id])
        ->json('chat.id');

    // Расход пишется из AIService по контексту — проверяем саму связку.
    AiUsageContext::set($company->id, $chatId, null, 'test_operation');
    AiUsage::record(700, 'test-model');
    AiUsageContext::clear();

    // Без контекста запись не должна появиться: иначе расход уедет чужой компании.
    AiUsage::record(999, 'test-model');

    $usage = $this->actingAs($user)->getJson('/api/company/usage')->assertOk()->json();
    expect($usage['used'])->toBe(700)
        ->and($usage['limit'])->toBeNull();

    // Ставим лимит ниже израсходованного.
    $this->actingAs($user)
        ->putJson('/api/company/usage', ['ai_token_limit' => 500])
        ->assertOk();

    $this->actingAs($user)
        ->postJson('/api/company/messages', ['chat_id' => $chatId, 'message' => 'привет'])
        ->assertStatus(429);

    // Пустой лимит снимает ограничение.
    $this->actingAs($user)
        ->putJson('/api/company/usage', ['ai_token_limit' => null])
        ->assertOk();

    expect(AiUsage::limitReached($company->fresh()))->toBeFalse();
});

it('требует файл только для загруженного файла', function () {
    [$company, $user] = makeCompany();

    // Внешняя база: файл не нужен, обновляется снимок схемы.
    $remote = makeSource($company, $user);
    expect((new DataSourceRefresher($remote, $user))->requiresFile())->toBeFalse();

    // Google-таблица: тоже без файла, тянется по сохранённой ссылке.
    $sheet = makeSource($company, $user, 'Таблица');
    $sheet->forceFill([
        'connection_type' => 'local',
        'origin_format' => 'google_sheets',
    ])->save();
    expect((new DataSourceRefresher($sheet->fresh(), $user))->requiresFile())->toBeFalse();

    // Загруженный CSV: нужна новая версия файла.
    $file = makeSource($company, $user, 'Выгрузка');
    $file->forceFill([
        'connection_type' => 'local',
        'origin_format' => 'csv',
    ])->save();
    expect((new DataSourceRefresher($file->fresh(), $user))->requiresFile())->toBeTrue();
});

it('для внешней базы обновляет снимок схемы, а не данные', function () {
    [$company, $user] = makeCompany();
    $source = makeSource($company, $user);

    // Подключение к выдуманному хосту не поднимется — и это правильный
    // ответ: обновление внешней базы начинается с проверки связи.
    $response = $this->actingAs($user)
        ->postJson("/api/company/data_source/{$source->id}/refresh");

    // Раньше здесь безусловно возвращалось 422 «обновлять нечего»;
    // теперь запрос доходит до реальной проверки подключения.
    expect($response->status())->toBeIn([200, 422]);
    expect($response->json('message'))->not->toContain('всегда актуальны');
});

it('меняет тип отрисовки виджета только внутри его семейства', function () {
    [$company, $user] = makeCompany();
    $source = makeSource($company, $user);

    $chat = AiChat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'data_source_id' => $source->id,
    ]);

    $dashboard = Dashboard::query()->create([
        'company_id' => $company->id,
        'chat_id' => $chat->id,
        'name' => 'Продажи',
        'status' => 'completed',
    ]);

    // Семейство с несколькими вариантами отрисовки.
    $family = Widget::query()->has('types', '>=', 2)->first();
    $types = $family->types()->get();

    $widget = DashboardWidget::query()->create([
        'dashboard_id' => $dashboard->id,
        'widget_id' => $family->id,
        'widget_type_id' => $types[0]->id,
        'instruction' => 'тест',
        'title' => 'Виджет',
        'position' => 0,
        'status' => 'active',
    ]);

    // Смена на соседний вариант того же семейства проходит.
    $this->actingAs($user)
        ->patchJson("/api/company/dashboards/{$dashboard->id}/widgets", [
            'widgets' => [['id' => $widget->id, 'widget_type_id' => $types[1]->id]],
        ])
        ->assertOk()
        ->assertJsonPath('updated', 1);

    expect($widget->fresh()->widget_type_id)->toBe($types[1]->id);

    // Тип чужого семейства отбивается: данные виджета посчитаны под его форму,
    // и, например, круг не нарисуется данными таблицы.
    $foreign = WidgetType::query()->where('widget_id', '!=', $family->id)->first();

    $this->actingAs($user)
        ->patchJson("/api/company/dashboards/{$dashboard->id}/widgets", [
            'widgets' => [['id' => $widget->id, 'widget_type_id' => $foreign->id]],
        ])
        ->assertStatus(422);

    expect($widget->fresh()->widget_type_id)->toBe($types[1]->id);
});

it('не даёт менять виджеты чужого дашборда', function () {
    [, $stranger] = makeCompany('Other');
    [$company, $user] = makeCompany('Acme');
    $source = makeSource($company, $user);

    $chat = AiChat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'data_source_id' => $source->id,
    ]);

    $dashboard = Dashboard::query()->create([
        'company_id' => $company->id,
        'chat_id' => $chat->id,
        'name' => 'Продажи',
        'status' => 'completed',
    ]);

    $this->actingAs($stranger)
        ->patchJson("/api/company/dashboards/{$dashboard->id}/widgets", [
            'widgets' => [['id' => 1, 'widget_type_id' => WidgetType::query()->value('id')]],
        ])
        ->assertNotFound();
});

it('фильтрует список чатов по источнику', function () {
    [$company, $user] = makeCompany();
    $sourceA = makeSource($company, $user, 'Продажи');
    $sourceB = makeSource($company, $user, 'Склад');

    $this->actingAs($user)->postJson('/api/company/chats', ['data_source_id' => $sourceA->id]);
    $this->actingAs($user)->postJson('/api/company/chats', ['data_source_id' => $sourceA->id]);
    $this->actingAs($user)->postJson('/api/company/chats', ['data_source_id' => $sourceB->id]);

    $chats = $this->actingAs($user)
        ->getJson('/api/company/chats?data_source_id=' . $sourceA->id)
        ->assertOk()
        ->json();

    expect($chats)->toHaveCount(2);
});
