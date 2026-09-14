<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DataSourceType extends Model
{

    public const KIND_FILE = 'file';

    public const KIND_DATABASE = 'database';

    public const KIND_API = 'api';

    protected $fillable = [
        'name',
        'label',
        'description',
        'kind',
        'icon',
        'default_port',
        'is_active',
        'position',
    ];

    protected $casts = [
        'default_port' => 'integer',
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isFile(): bool
    {
        return $this->kind === self::KIND_FILE;
    }

    public function isDatabase(): bool
    {
        return $this->kind === self::KIND_DATABASE;
    }

    public function isApi(): bool
    {
        return $this->kind === self::KIND_API;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->label ?: $this->name;
    }
}
