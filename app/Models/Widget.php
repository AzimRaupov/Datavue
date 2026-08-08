<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Widget extends Model
{
    protected $fillable = ['name', 'description', 'scheme', 'scheme_description', 'is_ai_selectable'];

    protected $casts = [
        'is_ai_selectable' => 'boolean',
    ];

    /**
     * Варианты отрисовки этого семейства (круг/кольцо/полукольцо и т.п.).
     */
    public function types(): HasMany
    {
        return $this->hasMany(WidgetType::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Типы, которые разрешено предлагать ИИ.
     */
    public function selectableTypes(): HasMany
    {
        return $this->types()->where('is_ai_selectable', true);
    }

    /**
     * Тип по умолчанию — на него откатываемся, если ИИ тип не выбрал
     * или назвал несуществующий.
     */
    public function defaultType(): ?WidgetType
    {
        $types = $this->relationLoaded('types') ? $this->types : $this->types()->get();

        return $types->firstWhere('is_default', true) ?? $types->first();
    }
}
