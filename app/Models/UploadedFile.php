<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UploadedFile extends Model
{

    protected $fillable = [
        'company_id',
        'message_id',
        'chat_id',
        'original_name',
        'file_path',
        'file_type',
        'file_size',
        'status',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class, 'file_id');
    }

    public function extractedData(): HasMany
    {
        return $this->hasMany(DataSourceExtraction::class, 'file_id');
    }
}
