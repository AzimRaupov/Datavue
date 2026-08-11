<?php

namespace App\Models;

use App\Helpers\Export\ExportFormat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Файл, сформированный по просьбе пользователя в чате.
 */
class ChatExport extends Model
{
    protected $fillable = [
        'company_id',
        'chat_id',
        'message_id',
        'token',
        'format',
        'title',
        'file_name',
        'path',
        'size',
        'rows_count',
        'total_rows',
        'truncated',
        'columns',
        'code',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'rows_count' => 'integer',
            'total_rows' => 'integer',
            'truncated' => 'boolean',
            'columns' => 'json',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Поля, которые не должны уезжать клиенту: путь на диске выдаёт структуру
     * хранилища, а код — целиком содержимое запроса к базе компании.
     */
    protected $hidden = ['path', 'code'];

    /**
     * Ссылка и человекочитаемый размер нужны фронту всегда, а вычисляются
     * из полей, которые он и так получает.
     */
    protected $appends = ['url', 'size_human', 'format_label'];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(AiChat::class, 'chat_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiChatMessage::class, 'message_id');
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }

    public function getUrlAttribute(): string
    {
        return route('chat-exports.download', ['token' => $this->token]);
    }

    public function getFormatLabelAttribute(): string
    {
        return ExportFormat::label((string) $this->format);
    }

    public function getSizeHumanAttribute(): string
    {
        $bytes = (int) $this->size;

        if ($bytes < 1024) {
            return $bytes.' Б';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' КБ';
        }

        return round($bytes / 1024 / 1024, 1).' МБ';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isDownloadable(): bool
    {
        return $this->status === 'ready'
            && !$this->isExpired()
            && is_file($this->path);
    }

    /**
     * Markdown-ссылка для вставки в ответ агента.
     */
    public function markdownLink(): string
    {
        // Формат и размер уже названы строкой выше в ответе — в подписи ссылки
        // достаточно имени файла.
        return sprintf('[⬇ Скачать %s](%s)', $this->file_name, $this->url);
    }
}
