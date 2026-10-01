<?php

namespace App\Console\Commands;

use App\Jobs\AlertCheckJob;
use App\Models\Alert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
