<?php

namespace App\Http\Controllers\Dashboard\Concerns;

use App\Helpers\Widget\WidgetQueryComposer;
use App\Helpers\Widget\WidgetSpecValidator;
use App\Models\DashboardWidget;
use Throwable;

trait PresentsWidgetContent
{

    protected function queryOf(DashboardWidget $widget): ?string
    {
        return WidgetSpecValidator::primaryQueryOf($widget->query_spec ?? []);
    }

    protected function requiredColumnsOf(DashboardWidget $widget): array
    {
        $family = $widget->widget?->name;

        if (!$family) {
            return [];
        }

        try {
            return WidgetSpecValidator::requiredColumns($family, $widget->effectiveType()?->name);
        } catch (Throwable) {

            return [];
        }
    }

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
