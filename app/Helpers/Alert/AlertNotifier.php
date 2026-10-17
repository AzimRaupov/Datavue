<?php

namespace App\Helpers\Alert;

use App\Mail\AlertBrokenMail;
use App\Mail\AlertResolvedMail;
use App\Mail\AlertTriggeredMail;
use App\Models\Alert;
use App\Models\AlertCheckerHistory;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AlertNotifier
{
    public const NOTICE_RESOLVED = 'resolved';
    public const NOTICE_ERROR = 'error';
    public const NOTICE_DISABLED = 'disabled';

    public function handleOk(Alert $alert, AlertCheckerHistory $check): array
    {
        $wasFiring = $alert->state === Alert::STATE_FIRING;

        $alert->state = Alert::STATE_OK;
        $alert->consecutive_failures = 0;

        // Проверка прошла — письмо «ошибка проверки», которое ещё не ушло, уже неактуально.
        if ($alert->pending_notice === self::NOTICE_ERROR) {
            $this->clearPending($alert);
        }

        $alert->save();

        if ($wasFiring && $alert->notify_on_resolve) {
            return $this->notify($alert, $check, self::NOTICE_RESOLVED);
        }

        return $this->none();
    }

    public function handleTriggered(Alert $alert, AlertCheckerHistory $check): array
    {
        $wasFiring = $alert->state === Alert::STATE_FIRING;
        $shouldNotify = !$wasFiring || $this->repeatDue($alert);

        $alert->state = Alert::STATE_FIRING;
        $alert->last_triggered_at = $check->checking_at;
        $alert->consecutive_failures = 0;

        // Алерт снова сработал: невысланные «вернулся в норму» и «ошибка» уже не про то, что происходит.
        if (in_array($alert->pending_notice, [self::NOTICE_RESOLVED, self::NOTICE_ERROR], true)) {
            $this->clearPending($alert);
        }

        if (!$shouldNotify) {
            $alert->save();

            return $this->none();
        }

        $alert->save();

        return $this->send($alert, $check, new AlertTriggeredMail($alert, $check));
    }

    public function handleError(Alert $alert, AlertCheckerHistory $check): array
    {
        $alert->state = Alert::STATE_ERROR;
        $alert->consecutive_failures++;

        $disableAfter = (int) config('alerts.disable_after_failures');
        $shouldDisable = $disableAfter > 0 && $alert->consecutive_failures >= $disableAfter;

        if ($shouldDisable) {
            $alert->is_active = false;
            $alert->disabled_reason = sprintf(
                '%d проверок подряд закончились ошибкой. Последняя: %s',
                $alert->consecutive_failures,
                $check->error
            );
        }

        $cooldownHours = (int) config('alerts.error_cooldown_hours');
        $cooledDown = !$alert->last_error_notified_at
            || $alert->last_error_notified_at->diffInHours(now()) >= $cooldownHours;

        // Письмо об этой же ошибке уже ждёт повторной отправки — второе такое же не нужно
        // (кроме случая, когда алерт только что отключился: об этом сказать обязательно).
        $alreadyPending = $alert->pending_notice === self::NOTICE_ERROR
            || $alert->pending_notice === self::NOTICE_DISABLED;

        $shouldNotify = $shouldDisable || ($cooledDown && !$alreadyPending);

        if (!$shouldNotify) {
            $alert->save();

            return $this->none() + ['disabled' => false];
        }

        $alert->save();

        // Паузу между письмами об ошибке «тратит» только письмо, которое действительно ушло.
        $result = $this->notify(
            $alert,
            $check,
            $shouldDisable ? self::NOTICE_DISABLED : self::NOTICE_ERROR
        );

        return $result + ['disabled' => $shouldDisable];
    }

    /**
     * Повторяет уведомления, которые не ушли из-за недоступной почты. Вызывается планировщиком
     * (alerts:dispatch) каждую минуту; своё время повтора у каждого уведомления.
     */
    public function retryPending(): int
    {
        $sent = 0;

        $due = Alert::query()
            ->whereNotNull('pending_notice')
            ->where('pending_retry_at', '<=', now())
            ->limit(100)
            ->get();

        foreach ($due as $alert) {
            $check = AlertCheckerHistory::query()->find($alert->pending_check_id);

            if (!$check) {
                $this->clearPending($alert);
                $alert->save();

                continue;
            }

            $result = $this->send($alert, $check, $this->mailFor($alert->pending_notice, $alert, $check));

            if ($result['notified']) {
                if ($alert->pending_notice !== self::NOTICE_RESOLVED) {
                    $alert->last_error_notified_at = now();
                }

                $this->clearPending($alert);
                $alert->save();
                $sent++;

                continue;
            }

            $this->schedulePending($alert, $check, $alert->pending_notice, $alert->pending_attempts + 1);
        }

        return $sent;
    }

    /** Отправляет уведомление; если не вышло — запоминает его для повтора. */
    private function notify(Alert $alert, AlertCheckerHistory $check, string $kind): array
    {
        $result = $this->send($alert, $check, $this->mailFor($kind, $alert, $check));

        if ($result['notified']) {
            if ($kind !== self::NOTICE_RESOLVED) {
                $alert->last_error_notified_at = now();
            }

            // Это же уведомление, ждавшее повтора, уже дошло.
            if ($alert->pending_notice === $kind) {
                $this->clearPending($alert);
            }

            $alert->save();

            return $result;
        }

        // Пустой список получателей не лечится повтором — только настройкой алерта.
        if ($result['recipients'] !== []) {
            $this->schedulePending($alert, $check, $kind, 1);
        }

        return $result;
    }

    private function schedulePending(Alert $alert, AlertCheckerHistory $check, string $kind, int $attempt): void
    {
        $limit = max(1, (int) config('alerts.notice_retry_attempts'));

        if ($attempt > $limit) {
            Log::warning('Alert: уведомление не доставлено после всех попыток, отказываемся', [
                'alert_id' => $alert->id,
                'notice' => $kind,
            ]);

            $this->clearPending($alert);
            $alert->save();

            return;
        }

        // 5, 10, 20, 40, 80 минут, дальше — раз в 2 часа.
        $delay = min(5 * (2 ** ($attempt - 1)), 120);

        $alert->pending_notice = $kind;
        $alert->pending_check_id = $check->id;
        $alert->pending_attempts = $attempt;
        $alert->pending_retry_at = now()->addMinutes($delay);
        $alert->save();
    }

    private function clearPending(Alert $alert): void
    {
        $alert->pending_notice = null;
        $alert->pending_check_id = null;
        $alert->pending_attempts = 0;
        $alert->pending_retry_at = null;
    }

    private function mailFor(string $kind, Alert $alert, AlertCheckerHistory $check): mixed
    {
        return match ($kind) {
            self::NOTICE_RESOLVED => new AlertResolvedMail($alert, $check),
            self::NOTICE_DISABLED => new AlertBrokenMail($alert, $check, true),
            default => new AlertBrokenMail($alert, $check, false),
        };
    }

    private function repeatDue(Alert $alert): bool
    {
        if (!$alert->last_notified_at) {
            return true;
        }

        return $alert->last_notified_at->diffInMinutes(now()) >= $alert->repeat_after_minutes;
    }

    private function send(Alert $alert, AlertCheckerHistory $check, mixed $mailable): array
    {
        $recipients = $this->resolveRecipients($alert);

        if ($recipients === []) {
            return [
                'notified' => false,
                'notified_at' => null,
                'recipients' => [],
                'notify_error' => 'У алерта не задано ни одного получателя.',
            ];
        }

        if ($check->csv_path && is_file($check->csv_path)) {
            $mailable->attach($check->csv_path, [
                'as' => 'alert-'.$alert->id.'-'.$check->id.'.csv',
                'mime' => 'text/csv',
            ]);
        }

        try {
            Mail::to($recipients)->send($mailable);

            $alert->last_notified_at = now();
            $alert->save();

            return [
                'notified' => true,
                'notified_at' => now(),
                'recipients' => $recipients,
                'notify_error' => null,
            ];
        } catch (Throwable $e) {

            Log::warning('Alert: письмо не отправлено', [
                'alert_id' => $alert->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'notified' => false,
                'notified_at' => null,
                'recipients' => $recipients,
                'notify_error' => $e->getMessage(),
            ];
        }
    }

    private function resolveRecipients(Alert $alert): array
    {
        $recipients = $alert->recipients ?? [];
        $userIds = $recipients['users'] ?? [];
        $emails = $recipients['emails'] ?? [];

        $userEmails = $userIds === [] ? [] : User::query()
            ->where('company_id', $alert->company_id)
            ->whereIn('id', $userIds)
            ->pluck('email')
            ->all();

        return array_values(array_unique(array_filter(array_merge($userEmails, $emails))));
    }

    private function none(): array
    {
        return ['notified' => false, 'notified_at' => null, 'recipients' => [], 'notify_error' => null];
    }
}
