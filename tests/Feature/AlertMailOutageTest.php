<?php

use App\Helpers\Alert\AlertNotifier;
use App\Mail\AlertBrokenMail;
use App\Mail\AlertResolvedMail;
use App\Mail\AlertTriggeredMail;
use App\Models\Alert;
use App\Models\AlertCheckerHistory;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/*
| Что происходит с уведомлениями алертов, если почта в нужный момент недоступна.
|
| Состояние алерта (сработал / в норме / ошибка) меняется при проверке, а письмо о нём уходит
| сразу следом. Если SMTP в эту секунду лежит, письмо нельзя терять: оно должно уйти, когда
| почта вернётся.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function outageAlert(array $override = []): Alert
{
    $company = Company::query()->create(['name' => 'Acme '.uniqid()]);

    $user = User::query()->create([
        'name' => 'Аналитик',
        'email' => 'a-'.uniqid().'@example.com',
        'password' => Hash::make('secret123'),
        'company_id' => $company->id,
        'is_active' => true,
    ]);

    $workspace = Workspace::query()->create(['company_id' => $company->id, 'created_by' => $user->id, 'name' => 'Пространство']);

    return Alert::query()->create(array_replace([
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'created_by' => $user->id,
        'title' => 'Тестовый алерт',
        'mode' => Alert::MODE_SQL,
        'query' => 'SELECT 1',
        'condition' => ['kind' => 'rows', 'op' => '>', 'threshold' => 0, 'on_empty' => 'ok'],
        'is_active' => true,
        'state' => Alert::STATE_UNKNOWN,
        'recipients' => ['emails' => ['ops@example.com']],
        'notify_on_resolve' => true,
        'repeat_after_minutes' => 1440,
    ], $override));
}

/** Перематывает время до момента повтора: «прошло достаточно минут, почта уже работает». */
function retryIsDue(Alert $alert): void
{
    Alert::query()->whereKey($alert->id)->whereNotNull('pending_notice')->update(['pending_retry_at' => now()->subMinute()]);
}

function outageCheck(Alert $alert, string $status, ?string $error = null): AlertCheckerHistory
{
    return AlertCheckerHistory::query()->create([
        'alert_id' => $alert->id,
        'checking_at' => now(),
        'finished_at' => now(),
        'status' => $status,
        'error' => $error,
        'trigger_source' => AlertCheckerHistory::SOURCE_SCHEDULE,
    ]);
}

it('письмо «сработал» не теряется: уходит на следующей проверке, когда почта вернулась', function () {
    $alert = outageAlert();
    $notifier = new AlertNotifier();

    smtpDown();
    $result = $notifier->handleTriggered($alert, outageCheck($alert, 'triggered'));

    expect($result['notified'])->toBeFalse()
        ->and($result['notify_error'])->toContain('недоступен')
        ->and($alert->fresh()->last_notified_at)->toBeNull();

    smtpUp();
    $result = $notifier->handleTriggered($alert->fresh(), outageCheck($alert, 'triggered'));

    expect($result['notified'])->toBeTrue();
    Mail::assertSent(AlertTriggeredMail::class, 1);
});

it('письмо «вернулся в норму» не теряется, если в момент возврата почта лежала', function () {
    $alert = outageAlert(['state' => Alert::STATE_FIRING]);
    $notifier = new AlertNotifier();

    smtpDown();
    $notifier->handleOk($alert, outageCheck($alert, 'ok'));

    expect($alert->fresh()->state)->toBe(Alert::STATE_OK);

    // Почта вернулась. Алерт продолжает проверяться, но «возврат в норму» уже случился — письмо о нём
    // обязано дойти и без нового срабатывания.
    smtpUp();
    retryIsDue($alert);
    (new AlertNotifier())->retryPending();

    Mail::assertSent(AlertResolvedMail::class, 1);
    expect($alert->fresh()->pending_notice)->toBeNull();
});

it('письмо об ошибке не теряется, и пауза «раз в сутки» не сгорает от неудачной отправки', function () {
    $alert = outageAlert();
    $notifier = new AlertNotifier();

    smtpDown();
    $notifier->handleError($alert, outageCheck($alert, 'error', 'таблицы нет'));

    expect($alert->fresh()->last_error_notified_at)->toBeNull('Пауза между письмами об ошибке потрачена, хотя письмо не ушло.');

    smtpUp();
    retryIsDue($alert);
    (new AlertNotifier())->retryPending();

    Mail::assertSent(AlertBrokenMail::class, fn ($mail) => $mail->disabled === false);
    expect($alert->fresh()->last_error_notified_at)->not->toBeNull();
});

it('письмо «алерт отключён» доходит, хотя после отключения проверок больше нет', function () {
    $alert = outageAlert(['consecutive_failures' => config('alerts.disable_after_failures') - 1]);

    smtpDown();
    (new AlertNotifier())->handleError($alert, outageCheck($alert, 'error', 'сломался'));

    expect($alert->fresh()->is_active)->toBeFalse();

    smtpUp();
    retryIsDue($alert);
    (new AlertNotifier())->retryPending();

    Mail::assertSent(AlertBrokenMail::class, fn ($mail) => $mail->disabled === true);
});

it('отложенное письмо повторяется не чаще, чем по расписанию задержек, и сдаётся после лимита попыток', function () {
    $alert = outageAlert(['state' => Alert::STATE_FIRING]);

    smtpDown();
    (new AlertNotifier())->handleOk($alert, outageCheck($alert, 'ok'));

    $alert->refresh();
    expect($alert->pending_notice)->toBe('resolved')
        ->and($alert->pending_attempts)->toBe(1)
        ->and($alert->pending_retry_at->isFuture())->toBeTrue();

    // Ещё не время — повтор ничего не делает и не тратит попытку.
    (new AlertNotifier())->retryPending();
    expect($alert->fresh()->pending_attempts)->toBe(1);

    // Почта лежит весь срок: после лимита попыток письмо бросаем, а не крутим вечно.
    for ($i = 0; $i < 50; $i++) {
        Alert::query()->whereKey($alert->id)->whereNotNull('pending_notice')->update(['pending_retry_at' => now()->subMinute()]);
        (new AlertNotifier())->retryPending();
    }

    expect($alert->fresh()->pending_notice)->toBeNull();
});

it('если алерт снова сработал, устаревшее письмо «вернулся в норму» не отправляется', function () {
    $alert = outageAlert(['state' => Alert::STATE_FIRING]);

    smtpDown();
    (new AlertNotifier())->handleOk($alert, outageCheck($alert, 'ok'));

    smtpUp();
    (new AlertNotifier())->handleTriggered($alert->fresh(), outageCheck($alert, 'triggered'));
    (new AlertNotifier())->retryPending();

    Mail::assertNotSent(AlertResolvedMail::class);
    expect($alert->fresh()->pending_notice)->toBeNull();
});

it('планировщик сам повторяет недоставленные уведомления', function () {
    $alert = outageAlert(['state' => Alert::STATE_FIRING]);

    smtpDown();
    (new AlertNotifier())->handleOk($alert, outageCheck($alert, 'ok'));
    Alert::query()->whereKey($alert->id)->update(['pending_retry_at' => now()->subMinute()]);

    smtpUp();
    Artisan::call('alerts:dispatch');

    Mail::assertSent(AlertResolvedMail::class, 1);
    expect($alert->fresh()->pending_notice)->toBeNull();
});
