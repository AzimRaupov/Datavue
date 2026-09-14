<?php

use App\Helpers\Alert\AlertChecker;
use App\Models\Alert;
use App\Models\AlertCheckerHistory;
use App\Models\Company;
use App\Models\DataSource;
use App\Models\DataSourceType;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    DataSourceType::query()->firstOrCreate(['name' => 'mysql']);

    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Тест требует MySQL: условие выполняет настоящая база.');
    }

    Schema::dropIfExists('alert_regression_probe');
    Schema::create('alert_regression_probe', function ($table) {
        $table->id();
        $table->decimal('total_usd', 10, 2);
    });

    \Illuminate\Support\Facades\DB::table('alert_regression_probe')->insert([
        ['total_usd' => 120.50],
        ['total_usd' => 30.00],
    ]);
});

afterEach(function () {
    if (config('database.default') === 'mysql') {
        Schema::dropIfExists('alert_regression_probe');
    }
});

it('находит колонку метрики без подписи по её реальному алиасу, а не по имени столбца', function () {
    $company = Company::query()->create(['name' => 'Acme']);

    $user = User::query()->create([
        'name' => 'Аналитик',
        'email' => 'analyst-'.uniqid().'@example.com',
        'password' => Hash::make('secret123'),
        'company_id' => $company->id,
        'is_active' => true,
    ]);
    $company->owner_id = $user->id;
    $company->save();
    $user->assignRole('company_admin');

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

    $workspace = Workspace::query()->create([
        'company_id' => $company->id,
        'created_by' => $user->id,
        'data_source_id' => $source->id,
        'name' => 'Пространство',
    ]);

    $response = $this->actingAs($user)->postJson("/api/company/workspaces/{$workspace->id}/alerts", [
        'title' => 'Тест',
        'mode' => Alert::MODE_BUILDER,
        'builder' => [
            'table' => 'alert_regression_probe',
            'metrics' => [
                ['agg' => 'sum', 'column' => 'total_usd', 'label' => ''],
            ],
        ],
        'condition' => [
            'kind' => 'value',
            'op' => '>',
            'threshold' => 0,
            'metric_index' => 0,
            'on_empty' => 'ok',
        ],
        'interval_minutes' => 60,
        'recipients' => ['emails' => ['ops@example.com']],
    ])->assertCreated()->json();

    expect($response['condition']['column'])
        ->not->toBe('total_usd')
        ->and($response['condition']['column'])->toContain('total_usd');

    $alert = Alert::find($response['id']);

    $check = (new AlertChecker())->check($alert, AlertCheckerHistory::SOURCE_MANUAL, notify: false);

    expect($check->status)->toBe(AlertCheckerHistory::STATUS_TRIGGERED)
        ->and($check->error)->toBeNull()
        ->and((float) $check->value)->toBe(150.50);
});
