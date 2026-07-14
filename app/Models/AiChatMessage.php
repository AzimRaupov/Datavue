<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiChatMessage extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'chat_id',
        'message',
        'answer',
        'tokens_used',
        'tool_results',
        'status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tokens_used' => 'integer',
            'tool_results' => 'json',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AiChatTask::class, 'message_id');
    }
    /**
     * Get the AI chat that owns the message.
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(AiChat::class, 'chat_id');
    }

    /**
     * Get the uploaded file associated with the message.
     */

    /**
     * Get all extracted data related to this message.
     */
    public function extractedData(): HasOne
    {
        return $this->hasOne(ExtractedData::class, 'message_id');
    }
}
