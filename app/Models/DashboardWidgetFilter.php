<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Фильтр, включённый у конкретного виджета, с его настройками.
 */
class DashboardWidgetFilter extends Model
{
    protected $fillable = [
        'dashboard_widget_id',
        'filter_key',
        'config',
        'position',
    ];

    protected $casts = [
        'config' => 'array',
        'position' => 'integer',
    ];

    public function widget(): BelongsTo
    {
        return $this->belongsTo(DashboardWidget::class, 'dashboard_widget_id');
    }

    /** Описание фильтра из каталога платформы. */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(WidgetFilter::class, 'filter_key', 'key');
    }
}
