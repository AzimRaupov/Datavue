<?php

use App\Jobs\AlertCheckJob;
use App\Mail\AlertBrokenMail;
use App\Mail\AlertResolvedMail;
use App\Mail\AlertTriggeredMail;
use App\Models\Alert;
use App\Models\AlertCheckerHistory;
use App\Models\Company;
use App\Models\DataSource;
use App\Models\DataSourceType;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Проверка алерта по расписанию — от alerts:dispatch до письма.
 *
 * Источником данных тесту служит та же база, на которой он запущен (см.
 * WidgetQuerySpecTest): AlertRunner выполняет настоящий SQL через настоящую
 * СУБД, а не подмену. Условия — литеральные SELECT ("SELECT 1 AS id"), а не
 * запрос к таблицам приложения: AlertRunner открывает СВОЁ соединение с базой
 * по кредам источника, отдельное от соединения, в котором RefreshDatabase
 * держит транзакцию теста, — данные, вставленные внутри этой транзакции,
 * другому соединению не видны.
 */

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    DataSourceType::query()->firstOrCreate(['name' => 'mysql']);

    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Тест требует MySQL: условие выполняет настоящая база.');
    }

    Mail::fake();
});

/** @return array{0: Company, 1: User, 2: Workspace} */
function makeDispatchFixture(): array
{
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

    return [$company, $user, $workspace];
}

function makeDispatchAlert(Company $company, User $user, Workspace $workspace, array $overrides = []): Alert
{
    return Alert::query()->create(array_replace([
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'data_source_id' => $workspace->data_source_id,
        'created_by' => $user->id,
        'title' => 'Есть пользователи',
        'mode' => Alert::MODE_SQL,
        'query' => "SELECT 1 AS id",
        'condition' => ['kind' => 'rows', 'op' => '>', 'threshold' => 0, 'on_empty' => 'ok'],
        'interval_minutes' => 15,
        'is_active' => true,
        'state' => Alert::STATE_UNKNOWN,
        'recipients' => ['emails' => ['ops@example.com']],
        'next_check_at' => now()->subMinute(),
    ], $overrides));
}

it('переходит в firing и отправляет письмо при первом срабатывании', function () {
    [$company, $user, $workspace] = makeDispatchFixture();
    $alert = makeDispatchAlert($company, $user, $workspace);

    Artisan::call('alerts:dispatch');

    $alert->refresh();

    expect($alert->state)->toBe(Alert::STATE_FIRING)
        ->and($alert->last_triggered_at)->not->toBeNull()
        ->and($alert->last_notified_at)->not->toBeNull()
        // next_check_at продвинут вперёд — атомарный захват не даст
        // повторно поставить этот же алерт в очередь раньше времени.
        ->and($alert->next_check_at->isFuture())->toBeTrue();

    Mail::assertSent(AlertTriggeredMail::class, 1);

    $check = AlertCheckerHistory::query()->where('alert_id', $alert->id)->sole();
    expect($check->status)->toBe(AlertCheckerHistory::STATUS_TRIGGERED)
        ->and($check->notified)->toBeTrue()
        ->and($check->trigger_source)->toBe(AlertCheckerHistory::SOURCE_SCHEDULE);
});

it('не повторяет письмо, пока условие держится в пределах паузы', function () {
    [$company, $user, $workspace] = makeDispatchFixture();
    $alert = makeDispatchAlert($company, $user, $workspace, [
        'state' => Alert::STATE_FIRING,
        'last_notified_at' => now()->subMinutes(5),
        'repeat_after_minutes' => 1440,
    ]);

    Artisan::call('alerts:dispatch');

    Mail::assertNotSent(AlertTriggeredMail::class);

    $check = AlertCheckerHistory::query()->where('alert_id', $alert->id)->sole();
    expect($check->status)->toBe(AlertCheckerHistory::STATUS_TRIGGERED)
        ->and($check->notified)->toBeFalse();
});

it('отправляет письмо о возврате в норму', function () {
    [$company, $user, $workspace] = makeDispatchFixture();
    $alert = makeDispatchAlert($company, $user, $workspace, [
        'query' => 'SELECT 1 AS id WHERE 1 = 0',
        'state' => Alert::STATE_FIRING,
        'notify_on_resolve' => true,
    ]);

    Artisan::call('alerts:dispatch');

    $alert->refresh();
    expect($alert->state)->toBe(Alert::STATE_OK);

    Mail::assertSent(AlertResolvedMail::class, 1);
});

it('не отправляет письмо о возврате в норму, если оно отключено', function () {
    [$company, $user, $workspace] = makeDispatchFixture();
    makeDispatchAlert($company, $user, $workspace, [
        'query' => 'SELECT 1 AS id WHERE 1 = 0',
        'state' => Alert::STATE_FIRING,
        'notify_on_resolve' => false,
    ]);

    Artisan::call('alerts:dispatch');

    Mail::assertNotSent(AlertResolvedMail::class);
});

it('помечает алерт error при сломанном запросе и не путает это с "не сработало"', function () {
    [$company, $user, $workspace] = makeDispatchFixture();
    $alert = makeDispatchAlert($company, $user, $workspace, [
        'query' => 'SELECT совсем не sql (',
    ]);

    Artisan::call('alerts:dispatch');

    $alert->refresh();
    expect($alert->state)->toBe(Alert::STATE_ERROR)
        ->and($alert->consecutive_failures)->toBe(1);

    Mail::assertSent(AlertBrokenMail::class, function ($mail) {
        return $mail->disabled === false;
    });

    $check = AlertCheckerHistory::query()->where('alert_id', $alert->id)->sole();
    expect($check->status)->toBe(AlertCheckerHistory::STATUS_ERROR)
        ->and($check->error)->not->toBeEmpty();
});

it('отключает алерт после серии ошибок подряд и явно сообщает причину', function () {
    config(['alerts.disable_after_failures' => 2, 'alerts.error_cooldown_hours' => 0]);

    [$company, $user, $workspace] = makeDispatchFixture();
    $alert = makeDispatchAlert($company, $user, $workspace, [
        'query' => 'SELECT совсем не sql (',
        'consecutive_failures' => 1,
    ]);

    Artisan::call('alerts:dispatch');

    $alert->refresh();
    expect($alert->is_active)->toBeFalse()
        ->and($alert->disabled_reason)->not->toBeEmpty();

    Mail::assertSent(AlertBrokenMail::class, function ($mail) {
        return $mail->disabled === true;
    });
});

it('atomic-захват не даёт повторно поставить в очередь ещё не подошедший алерт', function () {
    [$company, $user, $workspace] = makeDispatchFixture();
    makeDispatchAlert($company, $user, $workspace);

    Artisan::call('alerts:dispatch');
    expect(AlertCheckerHistory::query()->count())->toBe(1);

    // Второй вызов сразу же: next_check_at уже в будущем, повторной
    // постановки в очередь и второго письма быть не должно.
    Artisan::call('alerts:dispatch');
    expect(AlertCheckerHistory::query()->count())->toBe(1);

    Mail::assertSent(AlertTriggeredMail::class, 1);
});

it('"Проверить сейчас" пишет в историю как manual и не отправляет писем', function () {
    [$company, $user, $workspace] = makeDispatchFixture();
    $alert = makeDispatchAlert($company, $user, $workspace);

    $this->actingAs($user)
        ->postJson("/api/company/alerts/{$alert->id}/run")
        ->assertOk()
        ->assertJson(['status' => AlertCheckerHistory::STATUS_TRIGGERED]);

    Mail::assertNothingSent();

    $check = AlertCheckerHistory::query()->where('alert_id', $alert->id)->sole();
    expect($check->trigger_source)->toBe(AlertCheckerHistory::SOURCE_MANUAL)
        ->and($check->notified)->toBeFalse();

    // Ручная проверка не двигает next_check_at и не входит в расписание —
    // только last_checked_at.
    expect($alert->fresh()->last_checked_at)->not->toBeNull();
});
