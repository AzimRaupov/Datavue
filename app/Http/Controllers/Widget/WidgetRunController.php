<?php

namespace App\Http\Controllers\Widget;

use App\Helpers\Widget\WidgetCodeRun;
use App\Helpers\Widget\WidgetQueryRunner;
use App\Helpers\Widget\WidgetShapeMapper;
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
            return $this->runQuerySpec($widget, $dataSource, $request);
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

    private function runQuerySpec(DashboardWidget $widget, DataSource $dataSource, Request $request)
    {
        $family = $widget->widget?->name;

        if (!$family) {
            return response()->json([
                'error' => 'У виджета не задано семейство — нечем определить форму данных.',
            ], 422);
        }

        [$filters, $input] = $this->tableParams($family, $request);

        try {
            $result = (new WidgetQueryRunner($dataSource))->run(
                spec: $widget->query_spec,
                family: $family,
                type: $widget->effectiveType()?->name,
                filters: $filters,
                input: $input
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

    /**
     * Таблица листается и ищется на сервере: набор строк может быть куда
     * больше одной страницы, а клиент видел бы только то, что пришло в ответе.
     * Остальные семейства получают данные целиком — их читаемость решает фронт.
     */
    private function tableParams(string $family, Request $request): array
    {
        if ((WidgetShapeMapper::FAMILY_SHAPES[$family] ?? null) !== WidgetShapeMapper::SHAPE_ROWS) {
            return [[], []];
        }

        $input = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:'.WidgetQueryRunner::MAX_PER_PAGE,
            'search' => 'sometimes|nullable|string|max:200',
            'sort_by' => 'sometimes|nullable|string|max:255',
            'sort_dir' => 'sometimes|nullable|in:asc,desc',
        ]);

        return [
            ['paginate' => ['per_page' => WidgetQueryRunner::DEFAULT_PER_PAGE], 'search' => []],
            $input,
        ];
    }
}
