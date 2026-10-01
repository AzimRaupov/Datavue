<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
        'csv_path',
        'csv_token',
        'csv_row_count',
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
        'csv_row_count' => 'integer',
    ];

    protected $hidden = ['csv_path'];

    protected $appends = ['csv_url'];

    public const STATUS_OK = 'ok';
    public const STATUS_TRIGGERED = 'triggered';
    public const STATUS_ERROR = 'error';

    public const SOURCE_SCHEDULE = 'schedule';
    public const SOURCE_MANUAL = 'manual';

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public static function newCsvToken(): string
    {
        return Str::random(48);
    }

    public function getCsvUrlAttribute(): ?string
    {
        return $this->csv_token ? route('alert-history.csv', ['token' => $this->csv_token]) : null;
    }
}
