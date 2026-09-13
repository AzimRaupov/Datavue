<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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

    /** Путь на диске наружу не отдаётся — только по нему знают, что скачивать. */
    protected $hidden = ['csv_path'];

    /** Готовая ссылка — фронту незачем знать токен и собирать её самому. */
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

    /**
     * Публичная ссылка на CSV этой проверки — как у выгрузок чата: без
     * файла (csv_path пуст, например у проверки с ошибкой) ссылки нет.
     */
    public function getCsvUrlAttribute(): ?string
    {
        return $this->csv_token ? route('alert-history.csv', ['token' => $this->csv_token]) : null;
    }
}
