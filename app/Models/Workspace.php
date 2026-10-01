<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    protected $fillable = [
        'company_id',
        'created_by',
        'data_source_id',
        'name',
        'description',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function dashboards(): HasMany
    {
        return $this->hasMany(Dashboard::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(AiChat::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function chat(): ?AiChat
    {
        return $this->chats()->orderBy('id')->first();
    }

    public function scopeOfCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
