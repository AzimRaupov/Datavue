<?php

namespace App\Jobs;

use App\Helpers\Alert\AlertChecker;
use App\Models\Alert;
use App\Models\AlertCheckerHistory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class AlertCheckJob implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout;

    public function __construct(public int $alertId)
    {
        $this->timeout = max(
            (int) config('alerts.sql_timeout'),
            (int) config('alerts.python_timeout')
        ) + 30;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('alert-check-'.$this->alertId))->dontRelease()];
    }

    public function handle(AlertChecker $checker): void
    {
        $alert = Alert::query()->find($this->alertId);

        if (!$alert || !$alert->is_active) {
            return;
        }

        try {
            $checker->check($alert, AlertCheckerHistory::SOURCE_SCHEDULE, notify: true);
        } catch (\Throwable $e) {

            Log::error('AlertCheckJob: непредвиденная ошибка', [
                'alert_id' => $this->alertId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
