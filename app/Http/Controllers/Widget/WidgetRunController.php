<?php

namespace App\Http\Controllers\Widget;

use App\Helpers\Widget\WidgetCodeRun;
use App\Helpers\Widget\WidgetQueryRunner;
use App\Helpers\Widget\WidgetSpecValidator;
use App\Http\Controllers\Controller;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use Illuminate\Http\Request;
use Throwable;

class WidgetRunController extends Controller
{
    public function run(
        int $id,
        Request $request,
        WidgetCodeRun $widgetCodeRun
    ) {
        $companyId = $request->user()->company_id;

        $widget = DashboardWidget::query()
            ->with(['dashboard', 'widget.types', 'widgetType'])
            ->whereHas('dashboard', fn ($query) => $query->where('company_id', $companyId))
            ->findOrFail($id);

        $dataSource = $widget->dashboard?->resolveDataSource();

        if (!$dataSource || $dataSource->company_id !== $companyId) {
            return response()->json([
                'error' => 'Источник данных для этого виджета не найден.',
            ], 422);
        }

        if (!$widget->hasContent()) {
            return response()->json([
                'output' => null,
                'pending' => true,
            ]);
        }

        if ($widget->usesQuerySpec()) {
            return $this->runQuerySpec($widget, $dataSource);
        }

        $result = $widgetCodeRun->run(
            widget: $widget,
            dataSource: $dataSource
        );

        if (isset($result['error'])) {
            return response()->json(
                $result,
                422
            );
        }

        return response()->json(
            $result
        );
    }

    private function runQuerySpec(DashboardWidget $widget, DataSource $dataSource)
    {
        $family = $widget->widget?->name;

        if (!$family) {
            return response()->json([
                'error' => 'У виджета не задано семейство — нечем определить форму данных.',
            ], 422);
        }

        try {
            $result = (new WidgetQueryRunner($dataSource))->run(
                spec: $widget->query_spec,
                family: $family,
                type: $widget->effectiveType()?->name
            );
        } catch (Throwable $e) {
            return response()->json([
                'error' => WidgetSpecValidator::cleanDatabaseError($e->getMessage()),
            ], 422);
        }

        if (!($result['ok'] ?? false)) {

            return response()->json([
                'error' => WidgetSpecValidator::cleanDatabaseError(
                    (string) ($result['error'] ?? 'Запрос виджета не выполнен.')
                ),
            ], 422);
        }

        return response()->json([
            'data' => $result['data'],
            'meta' => $result['meta'] ?? null,
        ]);
    }
}
