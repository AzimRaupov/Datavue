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

    public function handleOk(Alert $alert, AlertCheckerHistory $check): array
    {
        $wasFiring = $alert->state === Alert::STATE_FIRING;

        $alert->state = Alert::STATE_OK;
        $alert->consecutive_failures = 0;
        $alert->save();

        if ($wasFiring && $alert->notify_on_resolve) {
            return $this->send($alert, $check, new AlertResolvedMail($alert, $check));
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

        $shouldNotify = $shouldDisable || $cooledDown;

        if (!$shouldNotify) {
            $alert->save();

            return $this->none() + ['disabled' => false];
        }

        $alert->last_error_notified_at = now();
        $alert->save();

        $result = $this->send($alert, $check, new AlertBrokenMail($alert, $check, $shouldDisable));

        return $result + ['disabled' => $shouldDisable];
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
