<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    protected $fillable = [
        'company_id',
        'chat_id',
        'message_id',
        'operation',
        'model',
        'tokens',
    ];

    protected $casts = [
        'tokens' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeCurrentMonth(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->startOfMonth());
    }
}
