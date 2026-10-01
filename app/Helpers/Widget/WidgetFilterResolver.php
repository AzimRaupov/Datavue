<?php

namespace App\Helpers\Widget;

use App\Models\DashboardWidget;
use App\Models\DashboardWidgetFilter;
use App\Models\WidgetFilter;
use Illuminate\Support\Facades\DB;

class WidgetFilterResolver
{

    private const DATE_TYPES = ['date', 'datetime', 'timestamp', 'time'];

    public static function hasDateColumn(array $tablesScheme): bool
    {
        foreach ($tablesScheme as $table) {
            foreach (($table['columns'] ?? []) as $column) {
                $type = strtolower((string) ($column['type'] ?? $column ?? ''));

                foreach (self::DATE_TYPES as $dateType) {
                    if (str_starts_with($type, $dateType)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public static function candidates(string $family, array $tablesScheme): array
    {
        return WidgetFilter::candidatesFor($family, self::hasDateColumn($tablesScheme))
            ->map(fn (WidgetFilter $filter) => [
                'key' => $filter->key,
                'label' => $filter->label,
                'description' => $filter->description,
            ])
            ->all();
    }

    public static function apply(DashboardWidget $widget, string $family, array $chosen): void
    {
        $required = WidgetFilter::requiredFor($family)->pluck('key')->all();

        $known = WidgetFilter::query()->active()->pluck('key')->all();

        $keys = array_values(array_unique(array_merge(
            $required,
            array_intersect($chosen, $known)
        )));

        DB::transaction(function () use ($widget, $keys) {
            DashboardWidgetFilter::query()
                ->where('dashboard_widget_id', $widget->id)
                ->delete();

            foreach ($keys as $position => $key) {
                DashboardWidgetFilter::create([
                    'dashboard_widget_id' => $widget->id,
                    'filter_key' => $key,
                    'config' => self::defaultConfig($key),
                    'position' => $position,
                ]);
            }
        });
    }

    private static function defaultConfig(string $key): array
    {
        return match ($key) {
            'paginate' => ['per_page' => WidgetQueryRunner::DEFAULT_PER_PAGE],
            default => [],
        };
    }

    public static function forRunner(DashboardWidget $widget): array
    {
        return $widget->filters
            ->mapWithKeys(fn (DashboardWidgetFilter $filter) => [
                $filter->filter_key => $filter->config ?? [],
            ])
            ->all();
    }
}
