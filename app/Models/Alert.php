<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Сохранённая проверка над источником рабочего пространства: раз в заданный
 * интервал платформа выполняет её сама и, если условие выполнилось, шлёт
 * письмо. Условие задаёт человек — метриками через конструктор, сырым SQL
 * или Python; ИИ в эту ветку не вовлечён.
 */
class Alert extends Model
{
    protected $fillable = [
        'company_id',
        'workspace_id',
        'data_source_id',
        'created_by',
        'title',
        'description',
        'mode',
        'builder',
        'query',
        'code',
        'condition',
        'interval_minutes',
        'is_active',
        'state',
        'next_check_at',
        'last_checked_at',
        'last_triggered_at',
        'last_notified_at',
        'last_error_notified_at',
        'consecutive_failures',
        'disabled_reason',
        'recipients',
        'repeat_after_minutes',
        'notify_on_resolve',
    ];

    protected $casts = [
        'builder' => 'array',
        'condition' => 'array',
        'recipients' => 'array',
        'is_active' => 'boolean',
        'notify_on_resolve' => 'boolean',
        'interval_minutes' => 'integer',
        'repeat_after_minutes' => 'integer',
        'consecutive_failures' => 'integer',
        'next_check_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'last_triggered_at' => 'datetime',
        'last_notified_at' => 'datetime',
        'last_error_notified_at' => 'datetime',
    ];

    protected $attributes = [
        'mode' => self::MODE_BUILDER,
        'state' => self::STATE_UNKNOWN,
    ];

    /** Условие собирается конструктором (метрики → SQL через WidgetQueryComposer). */
    public const MODE_BUILDER = 'builder';

    /** Условие — сырой SELECT/WITH под ReadOnlySqlGuard. */
    public const MODE_SQL = 'sql';

    /** Условие — тело main() на Python, как у ручных виджетов. */
    public const MODE_PYTHON = 'python';

    public const STATE_UNKNOWN = 'unknown';
    public const STATE_OK = 'ok';
    public const STATE_FIRING = 'firing';
    public const STATE_ERROR = 'error';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function history(): HasMany
    {
        return $this->hasMany(AlertCheckerHistory::class);
    }

    public function scopeOfCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /** Готов ли алерт к проверке прямо сейчас. */
    public function scopeDue(Builder $query)
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q) {
                $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now());
            });
    }

    public function isManual(): bool
    {
        return $this->mode === self::MODE_PYTHON;
    }
}
