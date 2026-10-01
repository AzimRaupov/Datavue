<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WidgetFilter extends Model
{

    public const APPLIES_WRAPPER = 'wrapper';

    public const APPLIES_QUERY = 'query';

    protected $fillable = [
        'key',
        'label',
        'description',
        'applies_as',
        'params',
        'required_for',
        'requires_date_column',
        'is_active',
        'position',
    ];

    protected $casts = [
        'params' => 'array',
        'required_for' => 'array',
        'requires_date_column' => 'boolean',
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function requiredFor(string $family)
    {
        return self::query()
            ->active()
            ->get()
            ->filter(fn (WidgetFilter $filter) => in_array($family, $filter->required_for ?? [], true))
            ->sortBy('position')
            ->values();
    }

    public static function candidatesFor(string $family, bool $hasDateColumn)
    {
        return self::query()
            ->active()
            ->orderBy('position')
            ->get()
            ->reject(fn (WidgetFilter $filter) => in_array($family, $filter->required_for ?? [], true))
            ->reject(fn (WidgetFilter $filter) => $filter->requires_date_column && !$hasDateColumn)
            ->values();
    }
}
