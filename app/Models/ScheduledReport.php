<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduledReport extends Model
{
    public const DAILY = 'daily';
    public const WEEKLY = 'weekly';
    public const MONTHLY = 'monthly';

    public const FREQUENCIES = [self::DAILY, self::WEEKLY, self::MONTHLY];

    protected $fillable = [
        'company_id',
        'workspace_id',
        'dashboard_id',
        'created_by',
        'title',
        'format',
        'frequency',
        'weekday',
        'day_of_month',
        'send_time',
        'timezone',
        'recipients',
        'is_active',
        'next_run_at',
        'last_run_at',
        'last_status',
        'consecutive_failures',
        'retry_attempts',
        'disabled_reason',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'is_active' => 'boolean',
            'weekday' => 'integer',
            'day_of_month' => 'integer',
            'consecutive_failures' => 'integer',
            'retry_attempts' => 'integer',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ScheduledReportRun::class);
    }

    /** Отчёты, которым пора уходить. */
    public function scopeDue(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now());
    }
}
