<?php

namespace App\Console\Commands;

use App\Helpers\Report\ReportSchedule;
use App\Jobs\ScheduledReportJob;
use App\Models\ScheduledReport;
use App\Models\ScheduledReportRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class DispatchDueReports extends Command
{
    protected $signature = 'reports:dispatch';

    protected $description = 'Ставит в очередь отправку отчётов по расписанию, которым подошло время';

    public function handle(): int
    {
        // Запись «отправляется», которая не закончилась за разумное время, — задачу убили. Закрываем её,
        // иначе кнопка «Отправить сейчас» отвечала бы «уже отправляется» до бесконечности.
        ScheduledReportRun::query()
            ->where('status', ScheduledReportRun::RUNNING)
            ->where('started_at', '<', now()->subMinutes((int) config('reports.stale_run_minutes')))
            ->update([
                'status' => ScheduledReportRun::FAILED,
                'error' => 'Отправка не завершилась: задача была прервана (перезапуск сервера или зависание).',
                'finished_at' => now(),
            ]);

        $due = ScheduledReport::query()->due()->limit((int) config('reports.dispatch_batch'))->get();

        $dispatched = 0;

        foreach ($due as $report) {
            try {
                $next = ReportSchedule::next($report);
            } catch (Throwable $e) {
                // Битое расписание не должно крутиться каждую минуту.
                $report->update([
                    'is_active' => false,
                    'disabled_reason' => 'Некорректное расписание: '.$e->getMessage(),
                ]);

                continue;
            }

            // Захват: next_run_at сдвигается обновлением с условием, поэтому один отчёт
            // не уйдёт дважды, даже если планировщик или воркеры работают параллельно.
            $claimed = DB::table('scheduled_reports')
                ->where('id', $report->id)
                ->where('is_active', true)
                ->where('next_run_at', '<=', now())
                ->update(['next_run_at' => $next, 'updated_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            ScheduledReportJob::dispatch($report->id);
            $dispatched++;
        }

        $this->info("Поставлено в очередь: {$dispatched}.");

        return self::SUCCESS;
    }
}
