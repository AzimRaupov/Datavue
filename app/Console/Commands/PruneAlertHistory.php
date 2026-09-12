<?php

namespace App\Console\Commands;

use App\Models\AlertCheckerHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Удаляет историю проверок алертов старше alerts.history_ttl_days вместе
 * с их CSV-файлами.
 *
 * Без этого таблица растёт бесконечно: алерт раз в час — это 24 строки
 * в сутки на один алерт, и через полгода их сотни тысяч, хотя ценность
 * представляет только недавняя история. CSV каждой проверки занимает место
 * на диске отдельно от строки в базе — забыть о нём значит оставить файлы
 * от давно удалённых записей висеть вечно (см. PruneChatExports, тот же приём).
 */
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
            // Каталог создаётся под каждую проверку отдельно (см. AlertChecker::
            // attachCsv) — удаляем его целиком, а не только файл, чтобы не
            // оставлять пустые каталоги-обрезки.
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
