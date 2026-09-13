<?php

namespace App\Console\Commands;

use App\Jobs\AlertCheckJob;
use App\Models\Alert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Выбирает алерты, которым пора проверяться, и ставит их в очередь.
 *
 * Каждый алерт «занимается» атомарным UPDATE ДО постановки в очередь: если
 * бы next_check_at продвигался после выполнения задачи, второй запуск этой
 * же команды (или её же запуск, наложившийся на медленный воркер) поставил
 * бы тот же алерт ещё раз — а условие, которое сработало, стало бы вторым
 * письмом. Проверка affected-строк — это и есть блокировка: выиграл её
 * ровно один вызов.
 *
 * next_check_at всегда считается от «сейчас», а не от старого значения:
 * если планировщик стоял пять часов, алерт проверяется один раз, а не пять
 * раз подряд.
 */
class DispatchDueAlerts extends Command
{
    protected $signature = 'alerts:dispatch';

    protected $description = 'Ставит в очередь проверку алертов, для которых подошло время';

    public function handle(): int
    {
        $batch = (int) config('alerts.dispatch_batch');

        $due = Alert::query()->due()->limit($batch)->get(['id', 'interval_minutes']);

        $dispatched = 0;

        foreach ($due as $candidate) {
            $alertId = $candidate->id;
            $nextCheckAt = now()->addMinutes(max(1, (int) $candidate->interval_minutes));

            // Атомарный захват: строка обновляется только если next_check_at
            // всё ещё в прошлом — ровно как её видела выборка выше. Если её
            // успел занять другой процесс, affected будет 0 и мы просто
            // пропускаем алерт вместо повторной постановки в очередь.
            $claimed = DB::table('alerts')
                ->where('id', $alertId)
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', now());
                })
                ->update(['next_check_at' => $nextCheckAt, 'updated_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            AlertCheckJob::dispatch($alertId);
            $dispatched++;
        }

        $this->info("Поставлено в очередь: {$dispatched}.");

        return self::SUCCESS;
    }
}
