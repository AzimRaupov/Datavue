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
        'data_source_id',
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

    /**
     * Источник данных, на котором заведён чат.
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    /**
     * Находит источник чата.
     *
     * Основной путь — data_source_id. Запасной, по старой связи
     * data_sources.chat_id, оставлен ради чатов, созданных до разделения
     * источников и чатов: у них новая колонка могла остаться пустой, если
     * источник добавили в обход миграции.
     */
    public function resolveDataSource(array $with = ['type', 'extracted']): ?DataSource
    {
        if ($this->data_source_id) {
            return DataSource::query()->with($with)->find($this->data_source_id);
        }

        return DataSource::query()
            ->with($with)
            ->where('chat_id', $this->id)
            ->first();
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
