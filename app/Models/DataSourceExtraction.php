<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataSourceExtraction extends Model
{

    protected $fillable = [
        'file_id',
        'company_id',
        'chat_id',
        'document_type',
        'data_path',
        'extracted_at',
    ];
    protected $table = 'data_source_extractions';

    protected function casts(): array
    {
        return [
            'extracted_at' => 'datetime',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(UploadedFile::class, 'file_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiChatMessage::class, 'message_id');
    }
}
