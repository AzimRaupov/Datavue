<?php

namespace App\Jobs;

use App\Helpers\Report\ReportSchedule;
use App\Models\DashboardExport;
use App\Models\ScheduledReport;
use App\Models\ScheduledReportRun;
use App\Models\User;
use App\Mail\ScheduledReportMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Отправка одного отчёта по расписанию: получить файл дашборда тем же механизмом, что и
 * ручной экспорт (DashboardExportJob), и отправить его по почте.
 *
 * Когда отчёту уходить в следующий раз, решает не задача, а диспетчер (reports:dispatch):
 * он сдвигает next_run_at до постановки в очередь, поэтому сбой отправки не «залипает».
 */
class ScheduledReportJob implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    // Ожидание общей блокировки Chrome (до 600 с) + съёмка + письмо.
    public $timeout = 1200;

    public function __construct(
        public int $reportId,
        public string $trigger = ScheduledReportRun::TRIGGER_SCHEDULE,
    ) {
    }

    public function handle(): void
    {
        $report = ScheduledReport::query()->with(['dashboard', 'company.owner', 'author'])->find($this->reportId);

        if (!$report) {
            return;
        }

        if ($this->trigger === ScheduledReportRun::TRIGGER_SCHEDULE && !$report->is_active) {
            return;
        }

        $run = ScheduledReportRun::query()->create([
            'scheduled_report_id' => $report->id,
            'trigger' => $this->trigger,
            'status' => ScheduledReportRun::RUNNING,
            'started_at' => now(),
        ]);

        $export = null;

        try {
            $recipients = $this->resolveRecipients($report);

            if ($recipients === []) {
                throw new RuntimeException('Не указан ни один получатель с адресом почты.');
            }

            if (!$report->dashboard) {
                throw new RuntimeException('Дашборд отчёта удалён.');
            }

            // Под чьим именем снимать дашборд: автор отчёта, а если его уже нет — владелец компании.
            $user = $report->author ?? $report->company?->owner;

            if (!$user) {
                throw new RuntimeException('У отчёта нет автора, а у компании — владельца.');
            }

            $export = DashboardExport::query()->create([
                'company_id' => $report->company_id,
                'dashboard_id' => $report->dashboard_id,
                'user_id' => $user->id,
                'token' => DashboardExport::newToken(),
                'format' => $report->format,
                'status' => DashboardExport::PENDING,
            ]);

            // Синхронно, в этой же задаче: письмо уходит сразу после готовности файла.
            app()->makeWith(DashboardExportJob::class, ['exportId' => $export->id])->handle();

            $export->refresh();

            if ($export->status !== DashboardExport::READY || !$export->isDownloadable()) {
                throw new RuntimeException($export->error ?: 'Не удалось сформировать файл.');
            }

            $size = (int) $export->size;
            $attach = $size <= (int) config('reports.max_attachment_mb') * 1024 * 1024;
            $generatedAt = now();

            if (!$attach) {
                // Файл остаётся на сервере, чтобы ссылка в письме работала.
                $export->update(['expires_at' => now()->addDays((int) config('reports.link_ttl_days'))]);
            }

            Mail::to($recipients)->send(new ScheduledReportMail(
                $report,
                (string) $report->dashboard->name,
                $generatedAt,
                url('/company/workspace/d/'.$report->dashboard_id),
                $attach ? $export->path : null,
                $attach ? $export->file_name : null,
                $attach ? null : $export->url,
            ));

            $run->update([
                'status' => ScheduledReportRun::SENT,
                'delivery' => $attach ? ScheduledReportRun::DELIVERY_ATTACHMENT : ScheduledReportRun::DELIVERY_LINK,
                'recipients' => $recipients,
                'file_size' => $size,
                'finished_at' => now(),
            ]);

            $this->registerSuccess($report);

            // Файл уже у получателей во вложении — копия на сервере не нужна.
            if ($attach) {
                $this->discard($export);
            }
        } catch (Throwable $e) {
            Log::error('ScheduledReportJob: failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);

            $run->update([
                'status' => ScheduledReportRun::FAILED,
                'error' => Str::limit($e->getMessage(), 500),
                'finished_at' => now(),
            ]);

            $this->registerFailure($report);

            if ($export) {
                $this->discard($export);
            }
        }
    }

    /** @return list<string> */
    private function resolveRecipients(ScheduledReport $report): array
    {
        $recipients = $report->recipients ?? [];

        $fromUsers = empty($recipients['users']) ? [] : User::query()
            ->where('company_id', $report->company_id)
            ->whereIn('id', $recipients['users'])
            ->pluck('email')
            ->all();

        $emails = array_merge($fromUsers, $recipients['emails'] ?? []);

        $unique = [];

        foreach ($emails as $email) {
            $email = trim((string) $email);

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $unique[Str::lower($email)] = $email;
            }
        }

        return array_values($unique);
    }

    private function registerSuccess(ScheduledReport $report): void
    {
        $update = [
            'last_run_at' => now(),
            'last_status' => ScheduledReportRun::SENT,
            'consecutive_failures' => 0,
            'retry_attempts' => 0,
        ];

        // Успешная отправка, пришедшая после быстрых повторов (или вручную вместо них), возвращает расписание
        // в обычную колею: ближайшее регулярное время, а не «через 5 минут».
        if ($this->trigger === ScheduledReportRun::TRIGGER_SCHEDULE || $report->retry_attempts > 0) {
            $update['next_run_at'] = ReportSchedule::next($report);
        }

        $report->update($update);
    }

    /**
     * Не вышло (почта или Chrome недоступны).
     *
     * Недельный отчёт не должен пропадать на неделю из-за пятиминутного сбоя, поэтому по расписанию
     * он повторяется через несколько минут (reports.retry_delays_minutes). И только когда все повторы
     * не помогли, срабатывание считается неудачным; после нескольких таких подряд отчёт выключается,
     * чтобы не занимать Chrome впустую. Ручная отправка расписание и счётчики не трогает.
     */
    private function registerFailure(ScheduledReport $report): void
    {
        $update = ['last_run_at' => now(), 'last_status' => ScheduledReportRun::FAILED];

        if ($this->trigger !== ScheduledReportRun::TRIGGER_SCHEDULE) {
            $report->update($update);

            return;
        }

        $delays = array_values((array) config('reports.retry_delays_minutes'));
        $attempt = $report->retry_attempts + 1;

        if ($attempt <= count($delays)) {
            $retryAt = now()->addMinutes((int) $delays[$attempt - 1]);

            $update['retry_attempts'] = $attempt;
            // Регулярное время уже назначено диспетчером; повтор — раньше него, если он ближе.
            $update['next_run_at'] = $report->next_run_at && $report->next_run_at->lessThan($retryAt)
                ? $report->next_run_at
                : $retryAt;

            $report->update($update);

            return;
        }

        $failures = $report->consecutive_failures + 1;

        $update['retry_attempts'] = 0;
        $update['consecutive_failures'] = $failures;

        $limit = (int) config('reports.disable_after_failures');

        if ($limit > 0 && $failures >= $limit) {
            $update['is_active'] = false;
            $update['disabled_reason'] = "Выключен: {$failures} срабатывания подряд не удалось отправить (со всеми повторами). Проверьте историю и почту, затем включите снова.";
        }

        $report->update($update);
    }

    /** Задачу убили (таймаут, перезапуск воркера) — запись «отправляется» не должна висеть вечно. */
    public function failed(?Throwable $exception = null): void
    {
        $run = ScheduledReportRun::query()
            ->where('scheduled_report_id', $this->reportId)
            ->where('status', ScheduledReportRun::RUNNING)
            ->latest('id')
            ->first();

        $run?->update([
            'status' => ScheduledReportRun::FAILED,
            'error' => 'Отправка прервана: '.Str::limit($exception?->getMessage() ?? 'задача остановлена', 300),
            'finished_at' => now(),
        ]);

        $report = ScheduledReport::query()->find($this->reportId);

        if ($report && $run) {
            $this->registerFailure($report);
        }
    }

    private function discard(DashboardExport $export): void
    {
        if ($export->path) {
            $directory = dirname($export->path);

            if (is_dir($directory) && str_contains($directory, '/exports/')) {
                File::deleteDirectory($directory);
            }
        }

        $export->delete();
    }
}
