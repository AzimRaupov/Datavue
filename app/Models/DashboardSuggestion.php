<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Готовый вариант дашборда для источника данных.
 *
 * Показывается в чате как кнопка: пользователь выбирает вариант — и его
 * `prompt` уходит агенту обычным сообщением, дальше работает штатный пайплайн.
 */
class DashboardSuggestion extends Model
{
    protected $fillable = [
        'data_source_id',
        'title',
        'prompt',
        'description',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
