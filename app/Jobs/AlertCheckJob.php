<?php

namespace App\Jobs;

use App\Helpers\Alert\AlertChecker;
use App\Models\Alert;
use App\Models\AlertCheckerHistory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Одна проверка одного алерта по расписанию.
 *
 * tries=1 намеренно: повтор задачи после сбоя означал бы вторую проверку
 * и, если условие сработало, второе письмо. AlertCheckJob сам пишет
 * результат — включая ошибку — в историю; то, что не удалось выполнить
 * проверку, само по себе фиксируется как status=error, а не теряется.
 */
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

    /**
     * Второй запуск того же алерта, пока первый ещё выполняется, не должен
     * стать вторым письмом — соседние проверки одного алерта сериализуются.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('alert-check-'.$this->alertId))->dontRelease()];
    }

    public function handle(AlertChecker $checker): void
    {
        $alert = Alert::query()->find($this->alertId);

        // Алерт мог быть выключен или удалён между постановкой в очередь
        // и выполнением задачи — это не ошибка, а нормальный исход гонки.
        if (!$alert || !$alert->is_active) {
            return;
        }

        try {
            $checker->check($alert, AlertCheckerHistory::SOURCE_SCHEDULE, notify: true);
        } catch (\Throwable $e) {
            // AlertChecker сам ловит ошибки условия и пишет их в историю;
            // сюда долетает только то, что сломалось в самой инфраструктуре
            // проверки (например, запись в БД) — это идёт в лог отдельно.
            Log::error('AlertCheckJob: непредвиденная ошибка', [
                'alert_id' => $this->alertId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
