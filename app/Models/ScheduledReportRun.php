<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledReportRun extends Model
{
    public const TRIGGER_SCHEDULE = 'schedule';
    public const TRIGGER_MANUAL = 'manual';

    public const RUNNING = 'running';
    public const SENT = 'sent';
    public const FAILED = 'failed';

    // Как файл дошёл до получателей: вложением или ссылкой (если слишком тяжёлый).
    public const DELIVERY_ATTACHMENT = 'attachment';
    public const DELIVERY_LINK = 'link';

    protected $fillable = [
        'scheduled_report_id',
        'trigger',
        'status',
        'delivery',
        'recipients',
        'file_size',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'file_size' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ScheduledReport::class, 'scheduled_report_id');
    }
}
