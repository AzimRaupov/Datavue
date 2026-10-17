<?php

use App\Helpers\Alert\AlertChecker;
use App\Jobs\ScheduledReportJob;
use App\Models\Alert;
use App\Models\AlertCheckerHistory;
use App\Models\Dashboard;
use App\Models\DashboardExport;
use App\Models\ScheduledReport;
use App\Models\ScheduledReportRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Живые тесты почты — отправляют НАСТОЯЩИЕ письма и проверяют, что они дошли
|--------------------------------------------------------------------------
|
| Обычный прогон тестов их пропускает. Чтобы запустить (ящик — тот, на который
| придут письма и в который тест заходит по IMAP для проверки; у Gmail это
| тот же MAIL_USERNAME с «паролем приложения»):
|
|   MAIL_LIVE_TEST_TO=you@gmail.com DB_CONNECTION=mysql \
|       ./vendor/bin/pest tests/Feature/Live --group=live
|
| Что нужно: настроенный MAIL_* в .env, локальная MySQL с данными (тест работает
| с обычной базой разработки и убирает за собой только то, что сам создал), запущенное
| приложение по EXPORT_RENDER_URL и Chrome (для отчётов). Порядок: сначала почта,
| потом отчёты, потом алерты.
|
*/

beforeEach(function () {
    $to = env('MAIL_LIVE_TEST_TO');

    if (!$to) {
        $this->markTestSkipped('Живые тесты почты выключены: задайте MAIL_LIVE_TEST_TO=адрес_ящика (см. комментарий в файле).');
    }

    if (app()->environment('production')) {
        $this->markTestSkipped('Живые тесты не запускаются на боевом окружении.');
    }

    $host = (string) config('database.connections.'.config('database.default').'.host');

    if (config('database.default') !== 'mysql' || !in_array($host, ['127.0.0.1', 'localhost'], true)) {
        $this->markTestSkipped('Живым тестам нужна локальная MySQL: запускайте с DB_CONNECTION=mysql.');
    }

    // phpunit.xml включает MAIL_MAILER=array — для живых тестов берём настоящий SMTP из .env.
    config(['mail.default' => 'smtp']);
    Mail::forgetMailers();

    $this->to = $to;
    $this->token = 'LIVE-'.Str::lower(Str::random(8));
    $this->startedAt = now()->subSecond();
    $this->reportIds = [];
    $this->alertIds = [];
});

afterEach(function () {
    // Убираем только то, что создал этот тест.
    foreach ($this->reportIds ?? [] as $id) {
        ScheduledReport::query()->whereKey($id)->delete();
    }

    foreach ($this->alertIds ?? [] as $id) {
        $alert = Alert::query()->find($id);

        if ($alert) {
            File::deleteDirectory(storage_path('app/company/'.$alert->company_id.'/alerts/'.$alert->id));
            $alert->delete();
        }
    }

    if (isset($this->startedAt)) {
        DashboardExport::query()->where('created_at', '>=', $this->startedAt)->each(function (DashboardExport $export) {
            if ($export->path && str_contains(dirname($export->path), '/exports/')) {
                File::deleteDirectory(dirname($export->path));
            }

            $export->delete();
        });
    }
});

/** Ждёт письмо с меткой в теме и возвращает его (или список писем). */
function liveInbox(string $token, int $expect = 1, int $wait = 90, ?string $saveDir = null): array
{
    $smtp = config('mail.mailers.smtp');

    $command = ['python3', base_path('scripts/mail_inbox.py'), '--token', $token, '--expect', (string) $expect, '--wait', (string) $wait];

    if ($saveDir) {
        array_push($command, '--save-dir', $saveDir);
    }

    // Логин и пароль — через окружение, не через аргументы.
    $process = new Process($command, base_path(), [
        'MAIL_IMAP_USER' => env('MAIL_IMAP_USER', $smtp['username']),
        'MAIL_IMAP_PASS' => env('MAIL_IMAP_PASS', $smtp['password']),
        'MAIL_IMAP_HOST' => env('MAIL_IMAP_HOST', preg_replace('/^smtp\./', 'imap.', (string) $smtp['host'])),
    ]);
    $process->setTimeout($wait + 60);
    $process->run();

    $result = json_decode(trim($process->getOutput()), true);

    expect($result)->toBeArray('Скрипт проверки ящика не вернул JSON: '.$process->getOutput().$process->getErrorOutput());
    expect($result['ok'])->toBeTrue('Не удалось проверить ящик: '.($result['error'] ?? ''));

    return $result['messages'];
}

function liveDashboard(): Dashboard
{
    $dashboard = Dashboard::query()
        ->whereHas('workspace', fn ($query) => $query->whereNotNull('data_source_id'))
        ->withCount('widgets')
        ->orderByDesc('widgets_count')
        ->first();

    expect($dashboard)->not->toBeNull('В базе разработки нет дашборда с виджетами.');

    return $dashboard;
}

function liveReport(object $test, array $override = []): ScheduledReport
{
    $dashboard = liveDashboard();

    $report = ScheduledReport::query()->create(array_replace([
        'company_id' => $dashboard->company_id,
        'workspace_id' => $dashboard->workspace_id,
        'dashboard_id' => $dashboard->id,
        'created_by' => $dashboard->company->owner_id,
        'title' => '['.$test->token.'] Живой отчёт',
        'format' => 'pdf',
        'frequency' => 'daily',
        'send_time' => '09:00',
        'timezone' => 'UTC',
        'recipients' => ['emails' => [$test->to]],
        'is_active' => true,
        // Не «просрочен»: на машине разработчика работает schedule:work и сразу подхватил бы отчёт.
        'next_run_at' => now()->addDay(),
    ], $override));

    $test->reportIds[] = $report->id;

    return $report;
}

function liveAlert(object $test, array $override = []): Alert
{
    $dashboard = liveDashboard();
    $workspace = $dashboard->workspace;

    $alert = Alert::query()->create(array_replace([
        'company_id' => $workspace->company_id,
        'workspace_id' => $workspace->id,
        'data_source_id' => $workspace->data_source_id,
        'created_by' => $dashboard->company->owner_id,
        'title' => '['.$test->token.'] Живой алерт',
        'mode' => Alert::MODE_SQL,
        'query' => 'SELECT 1 AS id',
        'condition' => ['kind' => 'rows', 'op' => '>', 'threshold' => 0, 'on_empty' => 'ok'],
        'interval_minutes' => 15,
        'is_active' => true,
        'state' => Alert::STATE_UNKNOWN,
        'recipients' => ['emails' => [$test->to]],
        'notify_on_resolve' => true,
        // Не «просрочен»: фоновый alerts:dispatch не должен забрать его раньше теста.
        'next_check_at' => now()->addDay(),
    ], $override));

    $test->alertIds[] = $alert->id;

    return $alert;
}

/** Ждёт, пока условие выполнится (задачу мог взять и фоновый воркер, поэтому просто ждём результат). */
function liveWaitFor(callable $condition, int $seconds = 150): mixed
{
    $deadline = time() + $seconds;

    do {
        $result = $condition();

        if ($result) {
            return $result;
        }

        sleep(2);
    } while (time() < $deadline);

    return $condition();
}

function liveRequireApp(): void
{
    $url = rtrim((string) config('exports.dashboard.render_url'), '/');

    try {
        $ok = Http::timeout(5)->get($url.'/up')->successful();
    } catch (Throwable) {
        $ok = false;
    }

    if (!$ok) {
        test()->markTestSkipped("Приложение не отвечает по {$url} (EXPORT_RENDER_URL) — отчёты снять не с чего.");
    }
}

// =============================================================================
// 1. Почта сама по себе
// =============================================================================

it('SMTP-сервер отвечает и принимает логин и пароль', function () {
    $transport = Mail::mailer('smtp')->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(EsmtpTransport::class);

    // start() — соединение, шифрование и вход под логином: упадёт при неверном пароле/хосте/порте.
    $transport->start();
    $transport->stop();
})->group('live', 'live-mail');

it('простое письмо доходит до ящика', function () {
    Mail::raw('Проверка доставки '.$this->token, fn ($m) => $m->to($this->to)->subject('Datavue '.$this->token));

    $messages = liveInbox($this->token);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['subject'])->toContain($this->token)
        ->and($messages[0]['folder'])->toBe('INBOX');
})->group('live', 'live-mail');

// =============================================================================
// 2. Отчёты по расписанию
// =============================================================================

it('отчёт по расписанию уходит сам: диспетчер → очередь → письмо с PDF', function () {
    liveRequireApp();
    config(['queue.default' => 'sync']);

    $report = liveReport($this, ['format' => 'pdf', 'next_run_at' => now()->subMinute()]);

    // Диспетчер забирает отчёт один раз — даже если параллельно работает фоновый планировщик.
    Artisan::call('reports:dispatch');

    $run = liveWaitFor(fn () => ScheduledReportRun::query()
        ->where('scheduled_report_id', $report->id)
        ->where('status', '!=', ScheduledReportRun::RUNNING)
        ->latest('id')->first());

    expect(ScheduledReportRun::query()->where('scheduled_report_id', $report->id)->count())->toBe(1, 'Отчёт ушёл больше одного раза за одно срабатывание.');
    expect($run)->not->toBeNull('Диспетчер не запустил отчёт.')
        ->and($run->status)->toBe(ScheduledReportRun::SENT, 'Отправка не удалась: '.$run?->error)
        ->and($run->delivery)->toBe(ScheduledReportRun::DELIVERY_ATTACHMENT)
        ->and($run->trigger)->toBe(ScheduledReportRun::TRIGGER_SCHEDULE);

    $messages = liveInbox($this->token, saveDir: sys_get_temp_dir().'/'.$this->token);

    expect($messages)->toHaveCount(1);

    $mail = $messages[0];
    $attachment = $mail['attachments'][0] ?? null;

    expect($mail['subject'])->toStartWith('Отчёт: ['.$this->token.']')
        ->and($mail['html'])->toContain(liveDashboard()->name)
        ->and(collect($mail['links'])->contains(fn ($link) => str_contains($link, '/company/workspace/d/'.liveDashboard()->id)))->toBeTrue()
        ->and($mail['attachments'])->toHaveCount(1)
        ->and($attachment['filename'])->toEndWith('.pdf')
        ->and($attachment['size'])->toBeGreaterThan(20000)
        ->and(file_get_contents($attachment['path'], false, null, 0, 5))->toBe('%PDF-');

    $report->refresh();

    expect($report->last_status)->toBe('sent')
        ->and($report->consecutive_failures)->toBe(0)
        ->and($report->next_run_at->isFuture())->toBeTrue('Следующая отправка не назначена.');

    // Файл у получателя уже есть — копия на сервере не копится.
    expect(DashboardExport::query()->where('created_at', '>=', $this->startedAt)->count())->toBe(0);

    File::deleteDirectory(sys_get_temp_dir().'/'.$this->token);
})->group('live', 'live-report');

it('отчёт в Word приходит валидным .docx с заголовками текстом', function () {
    liveRequireApp();

    $report = liveReport($this, ['format' => 'docx']);
    $scheduledFor = $report->next_run_at;

    (new ScheduledReportJob($report->id, ScheduledReportRun::TRIGGER_MANUAL))->handle();

    $run = ScheduledReportRun::query()->where('scheduled_report_id', $report->id)->latest('id')->firstOrFail();
    expect($run->status)->toBe(ScheduledReportRun::SENT, 'Отправка не удалась: '.$run->error)
        ->and($run->trigger)->toBe(ScheduledReportRun::TRIGGER_MANUAL);

    $messages = liveInbox($this->token, saveDir: sys_get_temp_dir().'/'.$this->token);
    $attachment = $messages[0]['attachments'][0] ?? null;

    expect($attachment)->not->toBeNull()
        ->and($attachment['filename'])->toEndWith('.docx');

    $zip = new ZipArchive();
    expect($zip->open($attachment['path']))->toBeTrue('Вложение — не архив .docx');

    $xml = $zip->getFromName('word/document.xml');
    $pictures = collect(range(0, $zip->numFiles - 1))->filter(fn ($i) => str_starts_with($zip->getNameIndex($i), 'word/media/'))->count();
    $zip->close();

    // Название дашборда — настоящий текст (редактируется), а виджеты — картинки.
    expect($xml)->toContain(htmlspecialchars(liveDashboard()->name, ENT_XML1))
        ->and($pictures)->toBeGreaterThan(0);

    // «Отправить сейчас» не трогает расписание.
    expect($report->fresh()->next_run_at->equalTo($scheduledFor))->toBeTrue('Ручная отправка сдвинула расписание.');

    File::deleteDirectory(sys_get_temp_dir().'/'.$this->token);
})->group('live', 'live-report');

it('тяжёлый файл приходит ссылкой, а не вложением, и ссылка скачивает отчёт', function () {
    liveRequireApp();
    config(['reports.max_attachment_mb' => 0]);

    $report = liveReport($this);

    (new ScheduledReportJob($report->id, ScheduledReportRun::TRIGGER_MANUAL))->handle();

    $run = ScheduledReportRun::query()->where('scheduled_report_id', $report->id)->latest('id')->firstOrFail();
    expect($run->status)->toBe(ScheduledReportRun::SENT, 'Отправка не удалась: '.$run->error)
        ->and($run->delivery)->toBe(ScheduledReportRun::DELIVERY_LINK);

    $mail = liveInbox($this->token)[0];

    expect($mail['attachments'])->toBeEmpty();

    $link = collect($mail['links'])->first(fn ($href) => str_contains($href, '/dashboard-exports/'));
    expect($link)->not->toBeNull('В письме нет ссылки на скачивание.');

    // Ссылка в письме ведёт на APP_URL, а проверяем её там, где приложение реально запущено.
    $path = parse_url($link, PHP_URL_PATH);
    $download = Http::timeout(30)->get(rtrim((string) config('exports.dashboard.render_url'), '/').$path);

    expect($download->status())->toBe(200)
        ->and($download->header('Content-Type'))->toContain('application/pdf')
        ->and(substr($download->body(), 0, 5))->toBe('%PDF-');
})->group('live', 'live-report');

it('адрес получателя не дублируется, если указан несколькими способами', function () {
    liveRequireApp();

    $report = liveReport($this, ['recipients' => ['emails' => [$this->to, Str::upper($this->to), ' '.$this->to.' ']]]);

    (new ScheduledReportJob($report->id, ScheduledReportRun::TRIGGER_MANUAL))->handle();

    $messages = liveInbox($this->token);

    expect($messages)->toHaveCount(1)
        ->and(substr_count(Str::lower($messages[0]['to']), Str::lower($this->to)))->toBe(1);
})->group('live', 'live-report');

it('если снять дашборд не удалось, письмо не уходит, а ошибка видна в истории', function () {
    liveRequireApp();
    config(['exports.dashboard.chrome_path' => '/nonexistent/chrome']);

    $report = liveReport($this);

    (new ScheduledReportJob($report->id, ScheduledReportRun::TRIGGER_MANUAL))->handle();

    $run = ScheduledReportRun::query()->where('scheduled_report_id', $report->id)->latest('id')->firstOrFail();

    expect($run->status)->toBe(ScheduledReportRun::FAILED)
        ->and($run->error)->toContain('снять дашборд')
        // Ручная отправка расписание и счётчики сбоев не трогает (по расписанию — отдельные повторы).
        ->and($report->fresh()->consecutive_failures)->toBe(0)
        ->and($report->fresh()->retry_attempts)->toBe(0)
        ->and($report->fresh()->last_status)->toBe('failed');

    // Пустой отчёт получателям отправляться не должен.
    expect(liveInbox($this->token, expect: 0, wait: 10))->toBeEmpty();
})->group('live', 'live-report');

// =============================================================================
// 3. Алерты
// =============================================================================

it('алерт сработал: письмо приходит с CSV, состояние — firing', function () {
    $alert = liveAlert($this);

    $check = (new AlertChecker())->check($alert, AlertCheckerHistory::SOURCE_MANUAL, notify: true);

    expect($check->status)->toBe(AlertCheckerHistory::STATUS_TRIGGERED)
        ->and($check->notified)->toBeTrue('Письмо не отправлено: '.$check->notify_error)
        ->and($check->recipients)->toBe([$this->to])
        ->and($alert->fresh()->state)->toBe(Alert::STATE_FIRING);

    $messages = liveInbox($this->token, saveDir: sys_get_temp_dir().'/'.$this->token);

    expect($messages)->toHaveCount(1);

    $mail = $messages[0];
    $csv = $mail['attachments'][0] ?? null;

    expect($mail['subject'])->toBe('Алерт сработал: ['.$this->token.'] Живой алерт')
        ->and($mail['html'])->toContain('Живой алерт')
        ->and($csv)->not->toBeNull()
        ->and($csv['filename'])->toBe('alert-'.$alert->id.'-'.$check->id.'.csv')
        ->and(file_get_contents($csv['path']))->toContain('id');

    File::deleteDirectory(sys_get_temp_dir().'/'.$this->token);
})->group('live', 'live-alert');

it('алерт вернулся в норму: приходит второе письмо', function () {
    $alert = liveAlert($this, ['state' => Alert::STATE_FIRING, 'query' => 'SELECT 1 AS id WHERE 1 = 0']);

    $check = (new AlertChecker())->check($alert, AlertCheckerHistory::SOURCE_MANUAL, notify: true);

    expect($check->status)->toBe(AlertCheckerHistory::STATUS_OK)
        ->and($check->notified)->toBeTrue('Письмо не отправлено: '.$check->notify_error)
        ->and($alert->fresh()->state)->toBe(Alert::STATE_OK);

    $mail = liveInbox($this->token)[0];

    expect($mail['subject'])->toContain('Живой алерт')
        ->and(Str::lower($mail['subject']))->toContain('норм');
})->group('live', 'live-alert');

it('сломанный алерт присылает письмо об ошибке, но не чаще раза в сутки', function () {
    $alert = liveAlert($this, ['query' => 'SELECT * FROM live_test_no_such_table']);
    $checker = new AlertChecker();

    $first = $checker->check($alert, AlertCheckerHistory::SOURCE_MANUAL, notify: true);

    expect($first->status)->toBe(AlertCheckerHistory::STATUS_ERROR)
        ->and($first->notified)->toBeTrue('Письмо не отправлено: '.$first->notify_error)
        ->and($alert->fresh()->state)->toBe(Alert::STATE_ERROR);

    $second = $checker->check($alert->fresh(), AlertCheckerHistory::SOURCE_MANUAL, notify: true);

    expect($second->status)->toBe(AlertCheckerHistory::STATUS_ERROR)
        ->and($second->notified)->toBeFalse('Повторная ошибка не должна слать письмо в пределах суток.');

    $messages = liveInbox($this->token);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['subject'])->toStartWith('Ошибка проверки алерта: ['.$this->token.']');
})->group('live', 'live-alert');

it('алерт срабатывает по расписанию: диспетчер → очередь → письмо', function () {
    config(['queue.default' => 'sync']);

    $alert = liveAlert($this, ['next_check_at' => now()->subMinute()]);

    Artisan::call('alerts:dispatch');

    $check = liveWaitFor(fn () => AlertCheckerHistory::query()->where('alert_id', $alert->id)->latest('id')->first());

    expect(AlertCheckerHistory::query()->where('alert_id', $alert->id)->count())->toBe(1, 'Проверка запущена больше одного раза за одно срабатывание.');
    expect($check)->not->toBeNull('Диспетчер не запустил проверку.')
        ->and($check->trigger_source)->toBe(AlertCheckerHistory::SOURCE_SCHEDULE)
        ->and($check->notified)->toBeTrue('Письмо не отправлено: '.$check->notify_error)
        ->and($alert->fresh()->next_check_at->isFuture())->toBeTrue();

    expect(liveInbox($this->token))->toHaveCount(1);
})->group('live', 'live-alert');
