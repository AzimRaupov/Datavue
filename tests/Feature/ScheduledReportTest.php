<?php

use App\Jobs\DashboardExportJob;
use App\Jobs\ScheduledReportJob;
use App\Mail\ScheduledReportMail;
use App\Models\DashboardExport;
use App\Models\ScheduledReportRun;
use App\Models\Company;
use App\Models\Dashboard;
use App\Models\ScheduledReport;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DashboardStatusesSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(DashboardStatusesSeeder::class);
});

function reportFixture(string $role = 'company_admin'): array
{
    $company = Company::query()->create(['name' => 'Acme '.uniqid()]);

    $user = User::query()->create([
        'name' => 'Сотрудник',
        'email' => 'u-'.uniqid().'@example.com',
        'password' => Hash::make('secret123'),
        'company_id' => $company->id,
        'is_active' => true,
    ]);

    $company->owner_id = $user->id;
    $company->save();
    $user->syncRoles([$role]);

    $workspace = Workspace::query()->create(['company_id' => $company->id, 'name' => 'Пространство']);
    $dashboard = Dashboard::query()->create([
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'name' => 'Продажи',
        'status' => 'completed',
    ]);

    return [$company, $user, $workspace, $dashboard];
}

function reportPayload(Dashboard $dashboard, array $override = []): array
{
    return array_merge([
        'title' => 'Недельный отчёт',
        'dashboard_id' => $dashboard->id,
        'format' => 'pdf',
        'frequency' => 'weekly',
        'weekday' => 1,
        'send_time' => '09:00',
        'timezone' => 'Asia/Dushanbe',
        'recipients' => ['emails' => ['boss@example.com']],
    ], $override);
}

it('создаёт отчёт и сразу считает следующую отправку', function () {
    [, $user, $workspace, $dashboard] = reportFixture();
    Sanctum::actingAs($user);

    $response = $this->postJson("/api/company/workspaces/{$workspace->id}/scheduled-reports", reportPayload($dashboard));

    $response->assertCreated()->assertJsonPath('frequency', 'weekly')->assertJsonPath('dashboard_name', 'Продажи');

    $report = ScheduledReport::query()->firstOrFail();
    expect($report->next_run_at)->not->toBeNull()
        ->and($report->next_run_at->isFuture())->toBeTrue()
        ->and($report->created_by)->toBe($user->id);
});

it('проверяет поля: день месяца, часовой пояс, время и получателей', function () {
    [, $user, $workspace, $dashboard] = reportFixture();
    Sanctum::actingAs($user);
    $url = "/api/company/workspaces/{$workspace->id}/scheduled-reports";

    $this->postJson($url, reportPayload($dashboard, ['frequency' => 'monthly']))->assertJsonValidationErrors('day_of_month');
    $this->postJson($url, reportPayload($dashboard, ['frequency' => 'monthly', 'day_of_month' => 31]))->assertJsonValidationErrors('day_of_month');
    $this->postJson($url, reportPayload($dashboard, ['timezone' => 'Mars/Base']))->assertJsonValidationErrors('timezone');
    $this->postJson($url, reportPayload($dashboard, ['send_time' => '25:99']))->assertJsonValidationErrors('send_time');
    $this->postJson($url, reportPayload($dashboard, ['format' => 'exe']))->assertJsonValidationErrors('format');
    $this->postJson($url, reportPayload($dashboard, ['recipients' => ['emails' => []]]))->assertStatus(422);

    expect(ScheduledReport::query()->count())->toBe(0);
});

it('не даёт взять дашборд другой компании', function () {
    [, $user, $workspace] = reportFixture();
    [, , , $foreignDashboard] = reportFixture();
    Sanctum::actingAs($user);

    $this->postJson("/api/company/workspaces/{$workspace->id}/scheduled-reports", reportPayload($foreignDashboard))
        ->assertJsonValidationErrors('dashboard_id');
});

it('не показывает и не меняет отчёты чужой компании', function () {
    [, $owner, $workspace, $dashboard] = reportFixture();
    Sanctum::actingAs($owner);
    $id = $this->postJson("/api/company/workspaces/{$workspace->id}/scheduled-reports", reportPayload($dashboard))->json('id');

    [, $stranger] = reportFixture();
    Sanctum::actingAs($stranger);

    $this->getJson("/api/company/scheduled-reports/{$id}")->assertNotFound();
    $this->deleteJson("/api/company/scheduled-reports/{$id}")->assertNotFound();
    $this->postJson("/api/company/scheduled-reports/{$id}/run")->assertNotFound();
    $this->getJson("/api/company/workspaces/{$workspace->id}/scheduled-reports")->assertNotFound();
});

it('смотреть отчёты может зритель, а создавать — нет', function () {
    [, $viewer, $workspace, $dashboard] = reportFixture('viewer');
    Sanctum::actingAs($viewer);

    $this->getJson("/api/company/workspaces/{$workspace->id}/scheduled-reports")->assertOk();
    $this->postJson("/api/company/workspaces/{$workspace->id}/scheduled-reports", reportPayload($dashboard))->assertForbidden();
});

it('включение пересчитывает время, чтобы отчёт не ушёл сразу из прошлого', function () {
    [, $user, $workspace, $dashboard] = reportFixture();
    Sanctum::actingAs($user);
    $id = $this->postJson("/api/company/workspaces/{$workspace->id}/scheduled-reports", reportPayload($dashboard))->json('id');

    $this->postJson("/api/company/scheduled-reports/{$id}/toggle")->assertJsonPath('is_active', false);

    ScheduledReport::query()->whereKey($id)->update(['next_run_at' => now()->subWeek()]);

    $this->postJson("/api/company/scheduled-reports/{$id}/toggle")->assertJsonPath('is_active', true);

    expect(ScheduledReport::query()->find($id)->next_run_at->isFuture())->toBeTrue();
});

it('«отправить сейчас» ставит задачу, не трогая расписание', function () {
    Queue::fake();
    [, $user, $workspace, $dashboard] = reportFixture();
    Sanctum::actingAs($user);
    $id = $this->postJson("/api/company/workspaces/{$workspace->id}/scheduled-reports", reportPayload($dashboard))->json('id');
    $before = ScheduledReport::query()->find($id)->next_run_at;

    $this->postJson("/api/company/scheduled-reports/{$id}/run")->assertStatus(202);

    Queue::assertPushed(ScheduledReportJob::class, fn ($job) => $job->reportId === $id && $job->trigger === 'manual');
    expect(ScheduledReport::query()->find($id)->next_run_at->equalTo($before))->toBeTrue();
});

it('диспетчер берёт только подошедшие включённые отчёты и не отправляет дважды', function () {
    Queue::fake();
    [$company, $user, $workspace, $dashboard] = reportFixture();

    $make = fn (array $attrs) => ScheduledReport::query()->create($attrs + [
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'dashboard_id' => $dashboard->id,
        'created_by' => $user->id,
        'title' => 'Отчёт',
        'format' => 'pdf',
        'frequency' => 'daily',
        'send_time' => '09:00',
        'timezone' => 'UTC',
        'recipients' => ['emails' => ['a@example.com']],
    ]);

    $due = $make(['next_run_at' => now()->subMinute()]);
    $later = $make(['next_run_at' => now()->addHour()]);
    $off = $make(['next_run_at' => now()->subMinute(), 'is_active' => false]);

    Artisan::call('reports:dispatch');
    Artisan::call('reports:dispatch');

    Queue::assertPushed(ScheduledReportJob::class, 1);
    Queue::assertPushed(ScheduledReportJob::class, fn ($job) => $job->reportId === $due->id);

    expect($due->fresh()->next_run_at->isFuture())->toBeTrue()
        ->and($later->fresh()->next_run_at->isFuture())->toBeTrue()
        ->and($off->fresh()->is_active)->toBeFalse();
});


// =============================================================================
// Отправка: подмена Chrome и почты
// =============================================================================

/** Вместо настоящей съёмки дашборда — готовый «PDF» нужного размера (или сбой). */
function fakeExport(bool $works = true, int $bytes = 4096): void
{
    app()->bind(DashboardExportJob::class, fn ($app, $params) => new class($params['exportId'], $works, $bytes) {
        public function __construct(private int $id, private bool $works, private int $bytes)
        {
        }

        public function handle(): void
        {
            $export = DashboardExport::query()->findOrFail($this->id);

            if (!$this->works) {
                $export->update(['status' => DashboardExport::FAILED, 'error' => 'Chrome недоступен']);

                return;
            }

            $directory = storage_path('app/testing/exports/'.$this->id.'-'.uniqid());
            File::ensureDirectoryExists($directory);
            file_put_contents($directory.'/otchet.pdf', '%PDF-1.4 '.str_repeat('x', $this->bytes));

            $export->update([
                'status' => DashboardExport::READY,
                'file_name' => 'otchet.pdf',
                'path' => $directory.'/otchet.pdf',
                'size' => filesize($directory.'/otchet.pdf'),
                'expires_at' => now()->addDays(7),
            ]);
        }
    });
}

function dueReport(array $override = []): ScheduledReport
{
    [$company, $user, $workspace, $dashboard] = reportFixture();

    return ScheduledReport::query()->create(array_replace([
        'company_id' => $company->id,
        'workspace_id' => $workspace->id,
        'dashboard_id' => $dashboard->id,
        'created_by' => $user->id,
        'title' => 'Недельный отчёт',
        'format' => 'pdf',
        'frequency' => 'weekly',
        'weekday' => 1,
        'send_time' => '09:00',
        'timezone' => 'UTC',
        'recipients' => ['users' => [$user->id], 'emails' => ['boss@example.com', 'BOSS@example.com', ' boss@example.com ']],
        'is_active' => true,
        'next_run_at' => now()->addWeek(),
    ], $override));
}

afterEach(function () {
    File::deleteDirectory(storage_path('app/testing'));
});

it('отправляет письмо с файлом во вложении, адреса не дублируются', function () {
    Mail::fake();
    fakeExport();
    $report = dueReport();

    (new ScheduledReportJob($report->id))->handle();

    Mail::assertSent(ScheduledReportMail::class, 1);
    Mail::assertSent(ScheduledReportMail::class, function (ScheduledReportMail $mail) use ($report) {
        $author = User::query()->find($report->created_by);

        return $mail->hasTo('boss@example.com')
            && $mail->hasTo($author->email)
            && count($mail->to) === 2
            && $mail->attachmentPath !== null
            && $mail->downloadUrl === null;
    });

    $run = ScheduledReportRun::query()->where('scheduled_report_id', $report->id)->sole();
    expect($run->status)->toBe('sent')->and($run->delivery)->toBe('attachment')->and($run->recipients)->toHaveCount(2);

    // Файл уже в письме — копия на сервере не копится.
    expect(DashboardExport::query()->count())->toBe(0);
});

it('слишком тяжёлый файл уходит ссылкой, а не вложением', function () {
    Mail::fake();
    fakeExport(bytes: 2 * 1024 * 1024);
    config(['reports.max_attachment_mb' => 1]);
    $report = dueReport();

    (new ScheduledReportJob($report->id))->handle();

    Mail::assertSent(ScheduledReportMail::class, fn ($mail) => $mail->attachmentPath === null && str_contains((string) $mail->downloadUrl, '/dashboard-exports/'));
    expect(ScheduledReportRun::query()->sole()->delivery)->toBe('link')
        ->and(DashboardExport::query()->count())->toBe(1);
});

it('если дашборд снять не удалось, письмо не уходит, а повтор назначается скоро, а не через неделю', function () {
    Mail::fake();
    fakeExport(works: false);
    $regular = now()->addWeek();
    $report = dueReport(['next_run_at' => $regular]);

    (new ScheduledReportJob($report->id))->handle();

    Mail::assertNothingSent();

    $report->refresh();
    expect(ScheduledReportRun::query()->sole()->status)->toBe('failed')
        ->and($report->retry_attempts)->toBe(1)
        // Срабатывание ещё не засчитано как неудачное: впереди повторы.
        ->and($report->consecutive_failures)->toBe(0)
        ->and($report->is_active)->toBeTrue()
        ->and($report->next_run_at->lessThan(now()->addMinutes(10)))->toBeTrue('Повтор должен быть через пару минут, а не в обычное время.');
});

it('сбой почты тоже повторяется, а удачный повтор возвращает расписание в обычную колею', function () {
    fakeExport();
    $report = dueReport();

    smtpDown();
    (new ScheduledReportJob($report->id))->handle();

    $report->refresh();
    expect($report->retry_attempts)->toBe(1)->and($report->last_status)->toBe('failed');

    smtpUp();
    (new ScheduledReportJob($report->id))->handle();

    Mail::assertSent(ScheduledReportMail::class, 1);

    $report->refresh();
    expect($report->retry_attempts)->toBe(0)
        ->and($report->consecutive_failures)->toBe(0)
        ->and($report->last_status)->toBe('sent')
        // Следующая отправка — снова по расписанию (понедельник 09:00), а не «через 5 минут».
        ->and($report->next_run_at->dayOfWeekIso)->toBe(1)
        ->and($report->next_run_at->format('H:i'))->toBe('09:00');
});

it('срабатывание считается неудачным только после всех повторов, а несколько таких подряд выключают отчёт', function () {
    Mail::fake();
    fakeExport(works: false);
    config(['reports.disable_after_failures' => 2]);
    $report = dueReport();
    $delays = count(config('reports.retry_delays_minutes'));

    // Первое срабатывание: все быстрые повторы подряд не помогли.
    foreach (range(1, $delays + 1) as $ignored) {
        (new ScheduledReportJob($report->id))->handle();
    }

    $report->refresh();
    expect($report->consecutive_failures)->toBe(1)->and($report->retry_attempts)->toBe(0)->and($report->is_active)->toBeTrue();

    // Второе срабатывание — снова все неудачи: отчёт выключается и объясняет почему.
    foreach (range(1, $delays + 1) as $ignored) {
        (new ScheduledReportJob($report->id))->handle();
    }

    $report->refresh();
    expect($report->is_active)->toBeFalse()->and($report->disabled_reason)->toContain('Выключен');
});

it('ручная отправка с ошибкой не трогает счётчики и расписание', function () {
    Mail::fake();
    fakeExport(works: false);
    // В базе время хранится с точностью до секунды — в тесте тоже.
    $regular = now()->addWeek()->startOfSecond();
    $report = dueReport(['next_run_at' => $regular]);

    (new ScheduledReportJob($report->id, ScheduledReportRun::TRIGGER_MANUAL))->handle();

    $report->refresh();
    expect($report->consecutive_failures)->toBe(0)
        ->and($report->retry_attempts)->toBe(0)
        ->and($report->is_active)->toBeTrue()
        ->and($report->next_run_at->equalTo($regular))->toBeTrue()
        ->and(ScheduledReportRun::query()->sole()->status)->toBe('failed');
});

it('отчёт без получателей с адресом не отправляется и объясняет причину', function () {
    Mail::fake();
    fakeExport();
    $report = dueReport(['recipients' => ['emails' => []]]);

    (new ScheduledReportJob($report->id, ScheduledReportRun::TRIGGER_MANUAL))->handle();

    Mail::assertNothingSent();
    expect(ScheduledReportRun::query()->sole()->error)->toContain('получател');
});

it('зависшую запись «отправляется» диспетчер закрывает, и «отправить сейчас» снова работает', function () {
    Queue::fake();
    $report = dueReport();

    $stuck = ScheduledReportRun::query()->create([
        'scheduled_report_id' => $report->id,
        'status' => 'running',
        'started_at' => now()->subHours(5),
    ]);

    Artisan::call('reports:dispatch');

    expect($stuck->fresh()->status)->toBe('failed')->and($stuck->fresh()->error)->toContain('прервана');

    $user = User::query()->find($report->created_by);
    Sanctum::actingAs($user);
    $this->postJson("/api/company/scheduled-reports/{$report->id}/run")->assertStatus(202);
});

it('если задачу убили, её запись закрывается и это считается сбоем срабатывания', function () {
    $report = dueReport();
    $run = ScheduledReportRun::query()->create(['scheduled_report_id' => $report->id, 'status' => 'running', 'started_at' => now()]);

    (new ScheduledReportJob($report->id))->failed(new RuntimeException('Job has timed out'));

    expect($run->fresh()->status)->toBe('failed')->and($run->fresh()->error)->toContain('timed out')
        ->and($report->fresh()->retry_attempts)->toBe(1);
});
