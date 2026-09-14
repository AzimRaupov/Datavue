<?php

namespace App\Console\Commands;

use App\Models\AlertCheckerHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PruneAlertHistory extends Command
{
    protected $signature = 'alerts:prune';

    protected $description = 'Удаляет протухшую историю проверок алертов';

    public function handle(): int
    {
        $ttlDays = (int) config('alerts.history_ttl_days');

        if ($ttlDays <= 0) {
            $this->info('alerts.history_ttl_days <= 0 — история не хранится с истечением, ничего не делаю.');

            return self::SUCCESS;
        }

        $expired = AlertCheckerHistory::query()
            ->where('checking_at', '<', now()->subDays($ttlDays))
            ->get();

        foreach ($expired as $check) {

            if ($check->csv_path) {
                $directory = dirname($check->csv_path);

                if (is_dir($directory) && str_contains($directory, '/history/')) {
                    File::deleteDirectory($directory);
                }
            }

            $check->delete();
        }

        $this->info('Удалено записей истории: '.$expired->count().'.');

        return self::SUCCESS;
    }
}
