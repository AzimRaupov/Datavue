<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class DashboardWidget extends Model
{
    protected $fillable = [
        'dashboard_id', 'widget_id', 'widget_type_id', 'instruction', 'title',
        'code_path', 'code', 'code_previous', 'content_mode', 'origin',

        'query_spec',
        'last_error', 'last_run_at', 'position', 'status', 'container', 'col', 'tables',
    ];

    protected $casts = [
        'last_run_at' => 'datetime',
        'query_spec' => 'array',
    ];

    protected $attributes = [
        'origin' => self::ORIGIN_AI,
        'content_mode' => 'python',
    ];

    protected $hidden = ['query_spec'];

    protected $appends = ['presentation'];

    public const MODE_SQL = 'sql';

    public const MODE_BUILDER = 'builder';

    public const MODE_PYTHON = 'python';

    public const ORIGIN_AI = 'ai';

    public const ORIGIN_MANUAL = 'manual';

    protected function presentation(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->query_spec['presentation'] ?? null,
        );
    }

    protected function tables(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => self::normalizeTables($value),
            set: fn ($value) => json_encode(self::normalizeTables($value), JSON_UNESCAPED_UNICODE),
        );
    }

    public static function normalizeTables(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        $depth = 0;

        while (is_string($value) && $depth++ < 5) {
            $decoded = json_decode($value, true);

            if ($decoded === null) {

                return trim($value) === '' ? [] : [$value];
            }

            $value = $decoded;
        }

        return is_array($value) ? array_values($value) : [];
    }

    public function dashboard()
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function isManual(): bool
    {
        return $this->origin === self::ORIGIN_MANUAL;
    }

    public function usesQuerySpec(): bool
    {
        $spec = $this->query_spec;

        return is_array($spec) && ($spec['queries'] ?? $spec['query'] ?? null) !== null;
    }

    public function resolveCode(): ?string
    {
        if (is_string($this->code) && trim($this->code) !== '') {
            return $this->code;
        }

        if ($this->code_path && is_file($this->code_path)) {
            return file_get_contents($this->code_path);
        }

        return null;
    }

    public function hasContent(): bool
    {
        return $this->usesQuerySpec() || $this->resolveCode() !== null;
    }

    public function widget()
    {
        return $this->belongsTo(Widget::class);
    }

    public function widgetType()
    {
        return $this->belongsTo(WidgetType::class);
    }

    public function effectiveType(): ?WidgetType
    {
        return $this->widgetType ?? $this->widget?->defaultType();
    }

    public function effectiveScheme(): ?string
    {
        return $this->effectiveType()?->effectiveScheme() ?? $this->widget?->scheme;
    }

    public function effectiveSchemeDescription(): ?string
    {
        return $this->effectiveType()?->effectiveSchemeDescription() ?? $this->widget?->scheme_description;
    }
}
