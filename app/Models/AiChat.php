<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiChat extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'company_id',
        'title',
        'status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }
    public function extractedData(): HasOne
    {
        return $this->hasOne(DataSourceExtraction::class, 'chat_id');
    }
    public function dashboard(): HasOne
    {
        return $this->hasOne(Dashboard::class, 'chat_id');
    }
    public function dashboards(): HasMany
    {
        return $this->hasMany(Dashboard::class, 'chat_id');
    }
    public function tasks(): HasMany
    {
        return $this->hasMany(AiChatTask::class, 'chat_id');
    }
    /**
     * Get the user that owns the AI chat.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the company that owns the AI chat.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get all messages for the AI chat.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class, 'chat_id');
    }
}
