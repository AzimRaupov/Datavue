<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{

    protected $fillable = [
        'name',
        'owner_id',
        'is_active',
        'ai_token_limit',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function aiChats(): HasMany
    {
        return $this->hasMany(AiChat::class);
    }

    public function uploadedFiles(): HasMany
    {
        return $this->hasMany(UploadedFile::class);
    }

    public function extractedData(): HasMany
    {
        return $this->hasMany(DataSourceExtraction::class);
    }

    public function dashboards(): HasMany
    {
        return $this->hasMany(Dashboard::class);
    }
}
