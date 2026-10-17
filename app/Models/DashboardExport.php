<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DashboardExport extends Model
{
    public const FORMATS = ['pdf', 'docx'];

    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const READY = 'ready';
    public const FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'dashboard_id',
        'user_id',
        'token',
        'format',
        'status',
        'file_name',
        'path',
        'size',
        'error',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    protected $hidden = ['path', 'token', 'error'];

    protected $appends = ['url'];

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }

    /** Ссылка на файл появляется только у готовой выгрузки. */
    public function getUrlAttribute(): ?string
    {
        return $this->status === self::READY
            ? route('dashboard-exports.download', ['token' => $this->token])
            : null;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::PENDING, self::PROCESSING], true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::READY
            && !$this->isExpired()
            && $this->path !== null
            && is_file($this->path);
    }
}
