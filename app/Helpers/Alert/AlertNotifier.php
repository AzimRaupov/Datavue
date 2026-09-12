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

/**
 * Машина состояний уведомлений алерта.
 *
 * Три правила, ради которых это отдельный класс, а не «отправить письмо
 * при triggered=true»:
 *
 *   1. Письмо уходит на ПЕРЕХОД в «сработал», не на каждую проверку, где
 *      условие всё ещё выполняется, — иначе «остаток ниже нормы» превращается
 *      в письмо каждый час.
 *   2. Пока условие держится, повтор — не чаще repeat_after_minutes.
 *   3. Возврат в норму — отдельное письмо (если включено notify_on_resolve):
 *      без него нельзя понять, когда переставать волноваться.
 *
 * Ошибка проверки — не то же самое, что срабатывание: за неё отвечает
 * отдельная ветка с собственным троттлингом и авто-отключением после
 * config('alerts.disable_after_failures') ошибок подряд.
 */
class AlertNotifier
{
    /**
     * @return array{notified: bool, notified_at: ?\Illuminate\Support\Carbon, recipients: array, notify_error: ?string}
     */
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

    /**
     * @return array{notified: bool, notified_at: ?\Illuminate\Support\Carbon, recipients: array, notify_error: ?string}
     */
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

    /**
     * @return array{notified: bool, notified_at: ?\Illuminate\Support\Carbon, recipients: array, notify_error: ?string, disabled: bool}
     */
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

    /**
     * @return array{notified: bool, notified_at: ?\Illuminate\Support\Carbon, recipients: array, notify_error: ?string}
     */
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
            // Письмо не ушло — это видно в истории проверки, а не только
            // в логе: автор алерта иначе решил бы, что его просто не задели.
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

    /**
     * Адреса сотрудников компании (по id из recipients.users) плюс
     * произвольные адреса (recipients.emails), без дублей.
     *
     * Идентификаторы сотрудников проверяются по company_id алерта — иначе
     * подменённый id из тела запроса на сохранении утащил бы письмо
     * сотруднику чужой компании.
     */
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
