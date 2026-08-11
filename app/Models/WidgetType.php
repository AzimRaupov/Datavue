<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WidgetType extends Model
{
    protected $fillable = [
        'widget_id',
        'name',
        'title',
        'description',
        'scheme',
        'scheme_description',
        'options',
        'is_default',
        'is_ai_selectable',
        'position',
    ];

    protected $casts = [
        'options' => 'array',
        'is_default' => 'boolean',
        'is_ai_selectable' => 'boolean',
        'position' => 'integer',
    ];

    public function widget(): BelongsTo
    {
        return $this->belongsTo(Widget::class);
    }

    /**
     * Форма данных для этого типа: своя, если задана, иначе — форма семейства.
     */
    public function effectiveScheme(): ?string
    {
        return $this->scheme ?: $this->widget?->scheme;
    }

    public function effectiveSchemeDescription(): ?string
    {
        return $this->scheme_description ?: $this->widget?->scheme_description;
    }
}
