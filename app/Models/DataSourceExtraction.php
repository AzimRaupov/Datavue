<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataSourceExtraction extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'file_id',
        'company_id',
        'chat_id',
        'document_type',
        'data_path',
        'extracted_at',
    ];
    protected $table = 'data_source_extractions';

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extracted_at' => 'datetime',
        ];
    }

    /**
     * Get the uploaded file that contains this data.
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(UploadedFile::class, 'file_id');
    }

    /**
     * Get the company that owns this extracted data.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the AI chat message that generated this data.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(AiChatMessage::class, 'message_id');
    }
}
