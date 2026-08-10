<?php

namespace App\Helpers\Widget;

use App\Models\DashboardWidget;
use App\Models\DashboardWidgetFilter;
use App\Models\WidgetFilter;
use Illuminate\Support\Facades\DB;

/**
 * Решает, какие фильтры получит виджет.
 *
 * Главная мысль: у модели спрашивают только то, чего нельзя вывести из данных.
 *
 *   Обязательные фильтры назначаются по семейству в коде. Таблице всегда
 *   нужны пагинация и поиск — тратить на этот вопрос промпт и токены незачем,
 *   и модель ещё могла бы ответить «не нужно».
 *
 *   Датовые фильтры вообще не предлагаются, если в таблицах виджета нет ни
 *   одной колонки с датой. Кандидаты отсеиваются механически, ДО обращения
 *   к модели, поэтому в промпт уходит короткий список из одного-двух
 *   вариантов, а не весь каталог.
 *
 * Так стоимость определения фильтров близка к нулю: обязательные — бесплатно,
 * остальные — одно дополнительное поле в том же запросе, который и так
 * генерирует SQL. Второго вызова модели не появляется.
 */
class WidgetFilterResolver
{
    /**
     * Типы колонок, которые считаем датой. Проверяется по началу строки,
     * поэтому ловятся и timestamp, и datetime(3), и date.
     */
    private const DATE_TYPES = ['date', 'datetime', 'timestamp', 'time'];

    /**
     * Есть ли в схеме виджета колонка с датой.
     *
     * @param array $tablesScheme Схема отобранных таблиц
     */
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

    /**
     * Короткий список фильтров, который имеет смысл показать модели.
     *
     * @return array<int, array{key: string, label: string, description: string}>
     */
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

    /**
     * Сохраняет набор фильтров виджета.
     *
     * @param array<int, string> $chosen Ключи, выбранные моделью
     */
    public static function apply(DashboardWidget $widget, string $family, array $chosen): void
    {
        $required = WidgetFilter::requiredFor($family)->pluck('key')->all();

        // Обязательные + выбранные, без дублей и без несуществующих ключей.
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

    /**
     * Настройки фильтра по умолчанию.
     */
    private static function defaultConfig(string $key): array
    {
        return match ($key) {
            'paginate' => ['per_page' => WidgetQueryRunner::DEFAULT_PER_PAGE],
            default => [],
        };
    }

    /**
     * Фильтры виджета в виде, который принимает WidgetQueryRunner.
     *
     * @return array<string, array>
     */
    public static function forRunner(DashboardWidget $widget): array
    {
        return $widget->filters
            ->mapWithKeys(fn (DashboardWidgetFilter $filter) => [
                $filter->filter_key => $filter->config ?? [],
            ])
            ->all();
    }
}
