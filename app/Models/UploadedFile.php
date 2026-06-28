<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UploadedFile extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
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

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Get the company that owns the uploaded file.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get all AI chat messages that reference this file.
     */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class, 'file_id');
    }

    /**
     * Get all extracted data from this file.
     */
    public function extractedData(): HasMany
    {
        return $this->hasMany(ExtractedData::class, 'file_id');
    }
}
