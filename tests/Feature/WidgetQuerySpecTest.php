<?php

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
use Database\Seeders\Widgets\MiniCountersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * Виджет, считающий SQL-запросом.
 *
 * Источником данных тесту служит та же база, на которой он запущен: запрос
 * должна выполнить настоящая СУБД, иначе проверка «колонки вернулись те,
 * что нужны виджету» ничего не проверяет — она вся построена на ответе базы.
 */

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(BarChartSeeder::class);
    $this->seed(MiniCountersSeeder::class);
    $this->seed(DashboardStatusesSeeder::class);

    DataSourceType::query()->firstOrCreate(['name' => 'mysql']);

    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Тест требует MySQL: запрос выполняет настоящая база.');
    }
});

/** @return array{0: Company, 1: User, 2: DataSource, 3: Dashboard} */
function makeQueryFixture(): array
{
    $company = Company::query()->create(['name' => 'Acme']);

    $user = User::query()->create([
        'name' => 'Аналитик',
        'email' => 'analyst-' . uniqid() . '@example.com',
        'password' => Hash::make('secret123'),
        'company_id' => $company->id,
        'is_active' => true,
    ]);

    $company->owner_id = $user->id;
    $company->save();
    $user->assignRole('company_admin');

    // Источник смотрит на тестовую базу: в ней есть таблицы приложения,
    // по которым можно посчитать что-то настоящее.
    $connection = config('database.connections.mysql');

    $source = DataSource::query()->create([
        'company_id' => $company->id,
        'created_by' => $user->id,
        'type_id' => DataSourceType::query()->where('name', 'mysql')->value('id'),
        'connection_type' => 'remote',
        'name' => 'Тестовая база',
        'host' => $connection['host'],
        'port' => $connection['port'],
        'database' => $connection['database'],
        'username' => $connection['username'],
        'password' => $connection['password'],
    ]);

    $dashboard = Dashboard::query()->create([
        'company_id' => $company->id,
        'created_by' => $user->id,
        'data_source_id' => $source->id,
        'name' => 'Показатели платформы',
        'status' => 'empty',
        'origin' => Dashboard::ORIGIN_MANUAL,
    ]);

    return [$company, $user->fresh(), $source, $dashboard];
}

function makeQueryWidget(Dashboard $dashboard, string $family): DashboardWidget
{
    $widget = Widget::query()->where('name', $family)->with('types')->firstOrFail();

    return DashboardWidget::query()->create([
        'dashboard_id' => $dashboard->id,
        'widget_id' => $widget->id,
        'widget_type_id' => $widget->defaultType()?->id,
        'title' => 'Виджет ' . $family,
        'instruction' => '',
        'position' => 0,
        'status' => 'draft',
        'origin' => DashboardWidget::ORIGIN_MANUAL,
    ]);
}

it('сохраняет запрос и раскладывает результат по форме виджета', function () {
    [, $user, , $dashboard] = makeQueryFixture();
    $widget = makeQueryWidget($dashboard, 'bar');

    // Запрос намеренно не обращается к таблицам приложения: источник данных
    // подключается отдельным соединением и не видит строк, которые тест
    // создал внутри своей транзакции. Проверяем здесь раскладку, а не выборку.
    $sql = "SELECT 'Продажи' AS series, 'Москва' AS category, 10 AS value
            UNION ALL SELECT 'Продажи', 'Питер', 7";

    $response = $this->actingAs($user)
        ->putJson("/api/company/dashboards/{$dashboard->id}/widgets/{$widget->id}/query", [
            'query' => $sql,
        ])
        ->assertOk()
        ->json();

    expect($response['ok'])->toBeTrue()
        ->and($response['saved'])->toBeTrue()
        // Раскладку сделал сервер: автор писал плоские строки.
        ->and($response['data']['categories'])->toBe(['Москва', 'Питер'])
        ->and($response['data']['series'])->toHaveCount(1)
        ->and($response['data']['series'][0]['name'])->toBe('Продажи')
        ->and($response['data']['series'][0]['data'])->toBe([10, 7]);

    $widget->refresh();

    expect($widget->status)->toBe('active')
        ->and($widget->content_mode)->toBe(DashboardWidget::MODE_SQL)
        ->and($widget->query_spec['shape'])->toBe('series_matrix')
        ->and($widget->query_spec['queries']['main'])->toContain('Москва')
        ->and($widget->last_error)->toBeNull();
});

it('заполняет пропуски нулями, чтобы ряды совпали с осью', function () {
    [, $user, , $dashboard] = makeQueryFixture();
    $widget = makeQueryWidget($dashboard, 'bar');

    // У ряда «2025» нет строки по Питеру — длины рядов разъехались бы,
    // и график съехал бы относительно подписей.
    $sql = "SELECT '2024' AS series, 'Москва' AS category, 10 AS value
            UNION ALL SELECT '2024', 'Питер', 7
            UNION ALL SELECT '2025', 'Москва', 12";

    $data = $this->actingAs($user)
        ->putJson("/api/company/dashboards/{$dashboard->id}/widgets/{$widget->id}/query", ['query' => $sql])
        ->assertOk()
        ->json('data');

    expect($data['categories'])->toBe(['Москва', 'Питер'])
        ->and($data['series'][0]['data'])->toBe([10, 7])
        ->and($data['series'][1]['data'])->toBe([12, 0]);
});

it('отдаёт содержимое виджета из запроса, а не из python', function () {
    [, $user, , $dashboard] = makeQueryFixture();
    $widget = makeQueryWidget($dashboard, 'mini-counters');

    // Здесь запрос идёт к настоящей таблице, которую видно и другому
    // соединению: схема создана миграциями, а не транзакцией теста.
    $this->actingAs($user)
        ->putJson("/api/company/dashboards/{$dashboard->id}/widgets/{$widget->id}/query", [
            'query' => 'SELECT \'Таблиц в базе\' AS name, COUNT(*) AS value
                        FROM information_schema.tables WHERE table_schema = DATABASE()',
        ])
        ->assertOk();

    $content = $this->actingAs($user)
        ->postJson("/api/company/get-widget-content/{$widget->id}")
        ->assertOk()
        ->json();

    // Готовая структура полем data — разбирать строку фронту не нужно.
    expect($content)->toHaveKey('data')
        ->and($content['data']['counters'][0]['name'])->toBe('Таблиц в базе')
        ->and($content['data']['counters'][0]['value'])->toBeGreaterThan(0)
        ->and($content)->not->toHaveKey('output');
});

it('отказывает, когда запрос вернул не те колонки', function () {
    [, $user, , $dashboard] = makeQueryFixture();
    $widget = makeQueryWidget($dashboard, 'bar');

    $response = $this->actingAs($user)
        ->putJson("/api/company/dashboards/{$dashboard->id}/widgets/{$widget->id}/query", [
            // Ни series, ни category, ни value — псевдонимы не заданы.
            'query' => "SELECT 'Москва' AS city, 10 AS total",
        ])
        ->assertStatus(422)
        ->json();

    expect($response['saved'])->toBeFalse()
        ->and(implode(' ', $response['errors']))->toContain('AS');

    // Спецификация не записана: сломанный виджет на дашборде не появляется.
    expect($widget->fresh()->query_spec)->toBeNull()
        ->and($widget->fresh()->status)->toBe('draft');
});

it('отказывает на несуществующей колонке словами базы', function () {
    [, $user, , $dashboard] = makeQueryFixture();
    $widget = makeQueryWidget($dashboard, 'bar');

    $response = $this->actingAs($user)
        ->putJson("/api/company/dashboards/{$dashboard->id}/widgets/{$widget->id}/query", [
            'query' => "SELECT 'x' AS series, nope AS category, 1 AS value",
        ])
        ->assertStatus(422)
        ->json();

    $message = implode(' ', $response['errors']);

    expect($message)->toContain('nope')
        // Реквизиты подключения к базе клиента в интерфейс не уезжают.
        ->and($message)->not->toContain('Connection:')
        ->and($message)->not->toContain('Host:');
});

it('не выполняет ничего, кроме чтения', function () {
    [, $user, , $dashboard] = makeQueryFixture();
    $widget = makeQueryWidget($dashboard, 'bar');

    foreach (['DELETE FROM dashboards', 'UPDATE dashboards SET name = 1', 'DROP TABLE dashboards'] as $sql) {
        $this->actingAs($user)
            ->putJson("/api/company/dashboards/{$dashboard->id}/widgets/{$widget->id}/query", [
                'query' => $sql,
            ])
            ->assertStatus(422);
    }

    // Таблица на месте — запросы даже не дошли до базы.
    expect(Dashboard::query()->count())->toBe(1);
});

it('не пускает к запросу без права write widget code', function () {
    [$company, , , $dashboard] = makeQueryFixture();
    $widget = makeQueryWidget($dashboard, 'bar');

    $editor = User::query()->create([
        'name' => 'Редактор',
        'email' => 'editor-' . uniqid() . '@example.com',
        'password' => Hash::make('secret123'),
        'company_id' => $company->id,
        'is_active' => true,
    ]);
    $editor->givePermissionTo(['view dashboards', 'edit dashboards']);

    $this->actingAs($editor->fresh())
        ->putJson("/api/company/dashboards/{$dashboard->id}/widgets/{$widget->id}/query", [
            'query' => 'SELECT 1 AS value',
        ])
        ->assertForbidden();
});

it('сохраняет виджет, собранный конструктором', function () {
    [, $user, , $dashboard] = makeQueryFixture();
    $widget = makeQueryWidget($dashboard, 'bar');

    // Считаем по таблице migrations: её строки записаны до теста и видны
    // другому соединению, в отличие от всего, что тест создал в транзакции.
    Cache::put("datasource:{$dashboard->data_source_id}:schema", [
        [
            'name' => 'migrations',
            'columns' => [
                ['name' => 'id', 'type' => 'int', 'kind' => 'number'],
                ['name' => 'migration', 'type' => 'varchar', 'kind' => 'string'],
                ['name' => 'batch', 'type' => 'int', 'kind' => 'number'],
            ],
        ],
    ], now()->addMinutes(5));

    $response = $this->actingAs($user)
        ->putJson("/api/company/dashboards/{$dashboard->id}/widgets/{$widget->id}/query", [
            'builder' => [
                'table' => 'migrations',
                'metrics' => [['agg' => 'count', 'label' => 'Миграций']],
                'dimensions' => [['column' => 'batch']],
                'limit' => 5,
            ],
        ])
        ->assertOk()
        ->json();

    expect($response['ok'])->toBeTrue()
        // Собранный запрос возвращается автору — как «View query» в Superset.
        ->and($response['sql'])->toContain('COUNT(*)')
        ->and($response['data']['series'][0]['name'])->toBe('Миграций');

    $widget->refresh();

    // В спецификации лежит и декларация, и собранный из неё запрос: первая
    // нужна, чтобы открыть виджет слотами, второй — чтобы его выполнять.
    expect($widget->query_spec['mode'])->toBe(DashboardWidget::MODE_BUILDER)
        ->and($widget->query_spec['builder']['table'])->toBe('migrations')
        ->and($widget->query_spec['queries']['main'])->toContain('GROUP BY')
        // Режим в колонке обязан совпадать с режимом в спецификации: разойдясь,
        // они говорили бы о виджете разное.
        ->and($widget->content_mode)->toBe(DashboardWidget::MODE_BUILDER);

    // И виджет отдаётся тем же путём, что у фронта.
    $content = $this->actingAs($user)
        ->postJson("/api/company/get-widget-content/{$widget->id}")
        ->assertOk()
        ->json();

    expect($content['data']['series'][0]['name'])->toBe('Миграций');
});

it('не собирает запрос по колонке, которой нет в источнике', function () {
    [, $user, , $dashboard] = makeQueryFixture();
    $widget = makeQueryWidget($dashboard, 'bar');

    Cache::put("datasource:{$dashboard->data_source_id}:schema", [
        ['name' => 'orders', 'columns' => [['name' => 'id', 'type' => 'int', 'kind' => 'number']]],
    ], now()->addMinutes(5));

    $response = $this->actingAs($user)
        ->putJson("/api/company/dashboards/{$dashboard->id}/widgets/{$widget->id}/query", [
            'builder' => [
                'table' => 'orders',
                'metrics' => [['agg' => 'count']],
                'dimensions' => [['column' => 'secret_column']],
            ],
        ])
        ->assertStatus(422)
        ->json();

    expect($response['errors'][0])->toContain('нет колонки')
        ->and($widget->fresh()->query_spec)->toBeNull();
});

it('отдаёт редактору контракт колонок вместе с виджетом', function () {
    [, $user, , $dashboard] = makeQueryFixture();
    makeQueryWidget($dashboard, 'bar');

    $response = $this->actingAs($user)
        ->getJson("/api/company/dashboards/{$dashboard->id}/edit")
        ->assertOk()
        ->json();

    // Подсказка в редакторе и проверка на сервере идут из одного источника.
    expect($response['widgets'][0]['required_columns'])->toBe(['series', 'category', 'value'])
        ->and($response['widgets'][0]['query'])->toBeNull();
});

it('переносит спецификацию при массовом создании виджета', function () {
    [, , , $dashboard] = makeQueryFixture();
    $bar = Widget::query()->where('name', 'bar')->firstOrFail();

    $spec = [
        'queries' => ['main' => "SELECT 'x' AS series, 'y' AS category, 1 AS value"],
        'shape' => 'series_matrix',
    ];

    // Так перегенерация переносит виджеты в новый дашборд. Пока query_spec
    // не было в fillable, массовое создание молча его отбрасывало, и виджет
    // приезжал в новый дашборд пустым.
    $widget = DashboardWidget::query()->create([
        'dashboard_id' => $dashboard->id,
        'widget_id' => $bar->id,
        'title' => 'Перенесённый',
        'instruction' => '',
        'position' => 0,
        'query_spec' => $spec,
        'content_mode' => DashboardWidget::MODE_SQL,
    ]);

    expect($widget->fresh()->query_spec)->toBe($spec)
        ->and($widget->fresh()->usesQuerySpec())->toBeTrue();
});

it('находит сломанный виджет на проверке дашборда', function () {
    [, , $source, $dashboard] = makeQueryFixture();
    $bar = Widget::query()->where('name', 'bar')->firstOrFail();

    DashboardWidget::query()->create([
        'dashboard_id' => $dashboard->id,
        'widget_id' => $bar->id,
        'widget_type_id' => $bar->defaultType()?->id,
        'title' => 'Рабочий',
        'instruction' => '',
        'position' => 0,
        'status' => 'active',
        'content_mode' => DashboardWidget::MODE_SQL,
        'query_spec' => [
            'queries' => ['main' => "SELECT 'Ряд' AS series, 'Ось' AS category, 5 AS value"],
            'shape' => 'series_matrix',
        ],
    ]);

    DashboardWidget::query()->create([
        'dashboard_id' => $dashboard->id,
        'widget_id' => $bar->id,
        'widget_type_id' => $bar->defaultType()?->id,
        'title' => 'Сломанный',
        'instruction' => '',
        'position' => 1,
        'status' => 'active',
        'content_mode' => DashboardWidget::MODE_SQL,
        'query_spec' => [
            'queries' => ['main' => "SELECT 'x' AS series, nope AS category, 1 AS value"],
            'shape' => 'series_matrix',
        ],
    ]);

    $review = new App\Helpers\Widget\ReviewWidgetsDashboard($dashboard->id, $source->id);
    $result = $review->review($review->dataSource, $review->dashboard_widgets);

    expect($result['isError'])->toBeTrue();

    $broken = collect($result['result'])->firstWhere('is_valid', false);
    $working = collect($result['result'])->firstWhere('is_valid', true);

    expect($working)->not->toBeNull()
        ->and($broken['errors'][0])->toContain('nope')
        // Реквизиты подключения не должны попадать даже в служебный отчёт.
        ->and($broken['errors'][0])->not->toContain('Host:');
});
