<?php

namespace App\Http\Controllers\Dashboard\Concerns;

use App\Helpers\Widget\WidgetQueryComposer;
use App\Helpers\Widget\WidgetSpecValidator;
use App\Models\DashboardWidget;
use Throwable;

/**
 * Поля виджета, нужные редактору: запрос, настройки конструктора и контракт,
 * которому запрос обязан соответствовать.
 *
 * Один и тот же набор отдают два контроллера — тот, что открывает конструктор
 * целиком, и тот, что возвращает один изменённый виджет. Копия этих трёх
 * методов жила в обоих, и разойтись им было достаточно одной правки: редактор
 * получал бы контракт из одного места, а проверка на сервере шла бы по другому.
 */
trait PresentsWidgetContent
{
    /**
     * Основной запрос виджета, если содержимое задано спецификацией.
     */
    protected function queryOf(DashboardWidget $widget): ?string
    {
        $queries = $widget->query_spec['queries'] ?? $widget->query_spec['query'] ?? null;

        if (is_string($queries)) {
            return $queries;
        }

        if (is_array($queries)) {
            $first = $queries['main'] ?? reset($queries);

            return is_string($first) ? $first : null;
        }

        return null;
    }

    /**
     * Колонки, которые обязан вернуть запрос этого виджета.
     *
     * @return array<int, string>
     */
    protected function requiredColumnsOf(DashboardWidget $widget): array
    {
        $family = $widget->widget?->name;

        if (!$family) {
            return [];
        }

        try {
            return WidgetSpecValidator::requiredColumns($family, $widget->effectiveType()?->name);
        } catch (Throwable) {
            // Семейство без описанной формы (например, ещё не подключённое) —
            // подсказки не будет, но редактор открыться должен.
            return [];
        }
    }

    /**
     * Сколько разбивок и метрик ждёт конструктор у этого виджета.
     */
    protected function slotsOf(DashboardWidget $widget): ?array
    {
        $family = $widget->widget?->name;

        if (!$family) {
            return null;
        }

        try {
            return WidgetQueryComposer::slotsFor($family, $widget->effectiveType()?->name);
        } catch (Throwable) {
            return null;
        }
    }
}
