<?php

namespace App\Console\Commands;

use App\Models\DashboardExport;
use App\Models\ScheduledReportRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class PruneDashboardExports extends Command
{
    protected $signature = 'dashboard-exports:prune';

    protected $description = 'Удаляет выгрузки дашбордов с истёкшим сроком жизни, зависшие задачи и старую историю отчётов';

    public function handle(): int
    {
        $removed = 0;

        $expired = DashboardExport::query()
            ->where(function ($query) {
                $query->where('expires_at', '<', now())
                    ->orWhere(function ($stuck) {
                        // Задача, которая так и не закончилась за сутки, уже не закончится.
                        $stuck->whereIn('status', [DashboardExport::PENDING, DashboardExport::PROCESSING, DashboardExport::FAILED])
                            ->where('created_at', '<', now()->subDay());
                    });
            })
            ->get();

        foreach ($expired as $export) {
            try {
                if ($export->path) {
                    $directory = dirname($export->path);

                    if (is_dir($directory) && str_contains($directory, '/exports/')) {
                        File::deleteDirectory($directory);
                    }
                }

                $export->delete();
                $removed++;
            } catch (Throwable $e) {
                $this->error('Не удалось удалить #'.$export->id.': '.$e->getMessage());
            }
        }

        // История отправок отчётов по расписанию хранится ограниченное время.
        $runs = ScheduledReportRun::query()
            ->where('created_at', '<', now()->subDays((int) config('reports.history_ttl_days')))
            ->delete();

        $this->info('Удалено выгрузок дашбордов: '.$removed.', записей истории отчётов: '.$runs);

        return self::SUCCESS;
    }
}
