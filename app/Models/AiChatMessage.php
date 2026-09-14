<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiChatMessage extends Model
{

    protected $fillable = [
        'chat_id',
        'message',
        'answer',

        'offer_type',
        'offer_summary',
        'tokens_used',
        'tool_results',
        'status',
    ];

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

    public function exports(): HasMany
    {
        return $this->hasMany(ChatExport::class, 'message_id');
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(AiChat::class, 'chat_id');
    }

    public function extractedData(): HasOne
    {
        return $this->hasOne(DataSourceExtraction::class, 'message_id');
    }
}
