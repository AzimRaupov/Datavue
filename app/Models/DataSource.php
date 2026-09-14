<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSource extends Model
{
    protected $fillable = [
        'company_id',
        'created_by',
        'chat_id',
        'type_id',
        'extracted_id',
        'connection_type',
        'origin_format',
        'name',
        'host',
        'port',
        'database',
        'username',
        'password',
        'path',
        'version',
        'options',
        'grouping_status',
        'grouping_stage',
        'grouping_message',
        'refreshed_at',
    ];

    protected $casts = [
        'port' => 'integer',
        'refreshed_at' => 'datetime',
        'options' => 'array',
    ];

    protected $hidden = [
        'password',
    ];

    public function extracted(): BelongsTo
    {
        return $this->belongsTo(DataSourceExtraction::class, 'extracted_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DataSourceType::class, 'type_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function chats(): HasMany
    {
        return $this->hasMany(AiChat::class, 'data_source_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(DataSourceGroup::class, 'data_source_id');
    }

    public function tables(): HasMany
    {
        return $this->hasMany(DataSourceTable::class, 'data_source_id');
    }

    public function scopeOfCompany(Builder $query, ?int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function isFileBased(): bool
    {
        return $this->connection_type === 'local';
    }

    protected const FORMAT_LABELS = [
        'csv' => 'CSV',
        'txt' => 'CSV',
        'xls' => 'Excel',
        'xlsx' => 'Excel',
        'sql' => 'SQL-дамп',
        'db' => 'SQLite',
        'sqlite' => 'SQLite',
        'sqlite3' => 'SQLite',
        'google_sheets' => 'Google Таблица',
    ];

    public function getFormatLabelAttribute(): string
    {
        if ($this->origin_format) {
            return self::FORMAT_LABELS[$this->origin_format]
                ?? mb_strtoupper($this->origin_format);
        }

        return $this->relationLoaded('type') && $this->type
            ? ($this->type->label ?: $this->type->name)
            : '—';
    }

    public function getFormatKeyAttribute(): string
    {
        if ($this->origin_format) {
            return in_array($this->origin_format, ['db', 'sqlite', 'sqlite3'], true)
                ? 'sqlite'
                : $this->origin_format;
        }

        return $this->relationLoaded('type') && $this->type ? $this->type->name : 'unknown';
    }

    protected $appends = ['format_label', 'format_key'];
}
