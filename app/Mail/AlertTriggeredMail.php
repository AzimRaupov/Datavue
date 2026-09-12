<?php

namespace App\Mail;

use App\Models\Alert;
use App\Models\AlertCheckerHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Условие алерта выполнилось: переход в «сработал» либо повтор после
 * repeat_after_minutes, пока условие продолжает выполняться.
 */
class AlertTriggeredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Alert $alert,
        public AlertCheckerHistory $check,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Алерт сработал: '.$this->alert->title)
            ->view('mail.alerts.triggered')
            ->with([
                'alert' => $this->alert,
                'check' => $this->check,
                'workspaceUrl' => url('/company/workspace/'.$this->alert->workspace_id.'?tab=alerts'),
            ]);
    }
}
