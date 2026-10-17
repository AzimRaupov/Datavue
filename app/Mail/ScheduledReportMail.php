<?php

namespace App\Mail;

use App\Models\ScheduledReport;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Письмо с отчётом по расписанию. Файл прикладывается, а если он слишком тяжёлый для почты —
 * вместо него в письме ссылка на скачивание.
 */
class ScheduledReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ScheduledReport $report,
        public string $dashboardName,
        public CarbonInterface $generatedAt,
        public string $dashboardUrl,
        public ?string $attachmentPath = null,
        public ?string $attachmentName = null,
        public ?string $downloadUrl = null,
    ) {
    }

    public function build(): self
    {
        $mail = $this->subject('Отчёт: '.$this->report->title.' — '.$this->generatedAt->format('d.m.Y'))
            ->view('mail.reports.scheduled')
            ->with([
                'report' => $this->report,
                'dashboardName' => $this->dashboardName,
                'generatedAt' => $this->generatedAt,
                'dashboardUrl' => $this->dashboardUrl,
                'downloadUrl' => $this->downloadUrl,
                'attached' => $this->attachmentPath !== null,
                'formatLabel' => $this->report->format === 'pdf' ? 'PDF' : 'Word (.docx)',
            ]);

        if ($this->attachmentPath !== null) {
            $mail->attach($this->attachmentPath, [
                'as' => $this->attachmentName,
                'mime' => $this->report->format === 'pdf'
                    ? 'application/pdf'
                    : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ]);
        }

        return $mail;
    }
}
