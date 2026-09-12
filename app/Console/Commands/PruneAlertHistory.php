<?php

namespace App\Console\Commands;

use App\Models\AlertCheckerHistory;
use Illuminate\Console\Command;

/**
 * Удаляет историю проверок алертов старше alerts.history_ttl_days.
 *
 * Без этого таблица растёт бесконечно: алерт раз в час — это 24 строки
 * в сутки на один алерт, и через полгода их сотни тысяч, хотя ценность
 * представляет только недавняя история.
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

        $deleted = AlertCheckerHistory::query()
            ->where('checking_at', '<', now()->subDays($ttlDays))
            ->delete();

        $this->info("Удалено записей истории: {$deleted}.");

        return self::SUCCESS;
    }
}
