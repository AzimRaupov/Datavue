<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одна строка — одна проверка алерта. Пишется всегда, включая ошибку:
 * иначе сломанная проверка выглядела бы как молчаливое бездействие.
 */
class AlertCheckerHistory extends Model
{
    protected $fillable = [
        'alert_id',
        'checking_at',
        'finished_at',
        'duration_ms',
        'status',
        'value',
        'matched_rows',
        'payload',
        'message',
        'error',
        'trigger_source',
        'notified',
        'notified_at',
        'recipients',
        'notify_error',
    ];

    protected $casts = [
        'checking_at' => 'datetime',
        'finished_at' => 'datetime',
        'notified_at' => 'datetime',
        'duration_ms' => 'integer',
        'matched_rows' => 'integer',
        'payload' => 'array',
        'recipients' => 'array',
        'notified' => 'boolean',
    ];

    public const STATUS_OK = 'ok';
    public const STATUS_TRIGGERED = 'triggered';
    public const STATUS_ERROR = 'error';

    public const SOURCE_SCHEDULE = 'schedule';
    public const SOURCE_MANUAL = 'manual';

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }
}
