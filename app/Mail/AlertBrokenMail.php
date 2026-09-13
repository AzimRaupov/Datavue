<?php

namespace App\Mail;

use App\Models\Alert;
use App\Models\AlertCheckerHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Проверка алерта завершилась ошибкой (запрос сломан, база недоступна, код
 * упал). Отправляется автору не чаще config('alerts.error_cooldown_hours'),
 * $disabled — когда ошибка привела к авто-отключению алерта.
 */
class AlertBrokenMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Alert $alert,
        public AlertCheckerHistory $check,
        public bool $disabled = false,
    ) {
    }

    public function build(): self
    {
        $subject = $this->disabled
            ? 'Алерт отключён из-за повторяющихся ошибок: '.$this->alert->title
            : 'Ошибка проверки алерта: '.$this->alert->title;

        return $this->subject($subject)
            ->view('mail.alerts.broken')
            ->with([
                'alert' => $this->alert,
                'check' => $this->check,
                'disabled' => $this->disabled,
                'workspaceUrl' => url('/company/workspace/'.$this->alert->workspace_id.'?tab=alerts'),
            ]);
    }
}
