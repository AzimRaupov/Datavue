<?php

namespace App\Http\Controllers\Dashboard;

use App\Events\DashboardWidgetChanged;
use App\Helpers\Widget\ManualWidgetAuthor;
use App\Helpers\Widget\WidgetQueryComposer;
use App\Helpers\Widget\WidgetSpecValidator;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\PresentsWidgetContent;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Widget;
use App\Models\WidgetType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DashboardWidgetController extends Controller
{
    use PresentsWidgetContent;

    public function store(Request $request, $dashboardId)
    {
        $dashboard = $this->findDashboard($request, $dashboardId);

        $data = $request->validate([
            'widget_id' => 'required|integer|exists:widgets,id',
            'widget_type_id' => 'nullable|integer|exists:widget_types,id',
            'title' => 'required|string|max:255',
            'instruction' => 'nullable|string',
        ]);

        $widget = Widget::query()->with('types')->findOrFail($data['widget_id']);
        $type = $this->resolveType($widget, $data['widget_type_id'] ?? null);

        $position = (int) DashboardWidget::query()
            ->where('dashboard_id', $dashboard->id)
            ->max('position');

        $created = DashboardWidget::query()->create([
            'dashboard_id' => $dashboard->id,
            'widget_id' => $widget->id,
            'widget_type_id' => $type?->id,
            'title' => $data['title'],

            'instruction' => $data['instruction'] ?? '',
            'tables' => [],
            'position' => $position + 1,
            'status' => 'draft',
            'origin' => DashboardWidget::ORIGIN_MANUAL,
            'content_mode' => 'python',
        ]);

        $this->syncDashboardStatus($dashboard);

        event(new DashboardWidgetChanged($dashboard));

        return response()->json($this->present($created->fresh(['widget.types', 'widgetType'])), 201);
    }

    public function update(Request $request, $dashboardId, $widgetId, ManualWidgetAuthor $author)
    {
        $dashboard = $this->findDashboard($request, $dashboardId);
        $widget = $this->findWidget($dashboard, $widgetId, ['widget.types', 'widgetType']);

        $data = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'widget_type_id' => 'sometimes|nullable|integer|exists:widget_types,id',
            'instruction' => 'sometimes|nullable|string',

            'presentation' => 'sometimes|nullable|array',
            'presentation.colors' => 'nullable|array|max:12',
            'presentation.colors.*' => 'nullable|string|max:32',
        ]);

        if (array_key_exists('widget_type_id', $data) && $data['widget_type_id']) {
            $type = WidgetType::query()->findOrFail($data['widget_type_id']);

            if ($type->widget_id !== $widget->widget_id) {
                throw ValidationException::withMessages([
                    'widget_type_id' => 'Тип отрисовки не подходит этому виджету.',
                ]);
            }

            $typeChanged = $widget->widget_type_id !== $type->id;
            $widget->widget_type_id = $type->id;
        }

        if (array_key_exists('title', $data)) {
            $widget->title = $data['title'];
        }

        if (array_key_exists('instruction', $data)) {
            $widget->instruction = $data['instruction'] ?? '';
        }

        if (array_key_exists('presentation', $data)) {
            $widget->query_spec = WidgetSpecValidator::withColors(
                $widget->query_spec ?? [],
                $data['presentation']['colors'] ?? null
            );
        }

        $widget->save();

        if (!empty($typeChanged)) {
            $dataSource = $dashboard->resolveDataSource();

            if ($dataSource) {
                $author->rebuildForType($widget->fresh(['widget.types', 'widgetType']), $dataSource);
            }
        }

        event(new DashboardWidgetChanged($dashboard));

        return response()->json($this->present($widget->fresh(['widget.types', 'widgetType'])));
    }

    public function destroy(Request $request, $dashboardId, $widgetId, ManualWidgetAuthor $author)
    {
        $dashboard = $this->findDashboard($request, $dashboardId);
        $widget = $this->findWidget($dashboard, $widgetId);

        $widget->setRelation('dashboard', $dashboard);
        $author->deleteFiles($widget);

        $widget->delete();

        $this->syncDashboardStatus($dashboard);

        event(new DashboardWidgetChanged($dashboard));

        return response()->json(['message' => 'Виджет удалён.']);
    }

    public function reorder(Request $request, $dashboardId)
    {
        $dashboard = $this->findDashboard($request, $dashboardId);

        $data = $request->validate([
            'widgets' => 'required|array|min:1',
            'widgets.*.id' => 'required|integer',
            'widgets.*.position' => 'required|integer|min:0',
        ]);

        $widgets = DashboardWidget::query()
            ->where('dashboard_id', $dashboard->id)
            ->whereIn('id', collect($data['widgets'])->pluck('id'))
            ->get()
            ->keyBy('id');

        $updated = 0;

        DB::transaction(function () use ($data, $widgets, &$updated) {
            foreach ($data['widgets'] as $row) {
                $widget = $widgets->get($row['id']);

                if (!$widget || $widget->position === (int) $row['position']) {
                    continue;
                }

                $widget->position = (int) $row['position'];
                $widget->save();
                $updated++;
            }
        });

        event(new DashboardWidgetChanged($dashboard));

        return response()->json(['success' => true, 'updated' => $updated]);
    }

    public function runQuery(Request $request, $dashboardId, $widgetId, ManualWidgetAuthor $author)
    {
        $dashboard = $this->findDashboard($request, $dashboardId);
        $widget = $this->findWidget($dashboard, $widgetId, ['widget.types', 'widgetType']);

        $data = $this->validateQuery($request);
        $source = $this->requireDataSource($dashboard);

        $result = $data['builder'] !== null
            ? $author->runBuilderDraft($widget, $data['builder'], $data['presentation'], $source)
            : $author->runQueryDraft($widget, $data['query'], $data['presentation'], $source);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function composeQuery(Request $request, $dashboardId, $widgetId)
    {
        $dashboard = $this->findDashboard($request, $dashboardId);
        $widget = $this->findWidget($dashboard, $widgetId, ['widget.types', 'widgetType']);

        $data = $this->validateQuery($request);

        if ($data['builder'] === null) {
            return response()->json(['sql' => $data['query']]);
        }

        $family = $widget->widget?->name;

        if (!$family) {
            return response()->json(['sql' => null, 'errors' => ['У виджета не задано семейство.']], 422);
        }

        $composed = (new WidgetQueryComposer($this->requireDataSource($dashboard)))->compose(
            $data['builder'],
            $family,
            $widget->effectiveType()?->name
        );

        return response()->json([
            'sql' => $composed['sql'],
            'errors' => $composed['errors'],
        ], $composed['ok'] ? 200 : 422);
    }

    public function saveQuery(Request $request, $dashboardId, $widgetId, ManualWidgetAuthor $author)
    {
        $dashboard = $this->findDashboard($request, $dashboardId);
        $widget = $this->findWidget($dashboard, $widgetId, ['widget.types', 'widgetType']);

        $data = $this->validateQuery($request);
        $source = $this->requireDataSource($dashboard);

        $widget->setRelation('dashboard', $dashboard);

        $result = $data['builder'] !== null
            ? $author->saveBuilder($widget, $data['builder'], $data['presentation'], $source)
            : $author->saveQuery($widget, $data['query'], $data['presentation'], $source);

        $this->syncDashboardStatus($dashboard);

        event(new DashboardWidgetChanged($dashboard));

        return response()->json(
            $result + ['widget' => $this->present($widget->fresh(['widget.types', 'widgetType']))],
            $result['saved'] ? 200 : 422
        );
    }

    private function validateQuery(Request $request): array
    {

        $data = $request->validate([

            'builder' => 'nullable|array',
            'builder.table' => 'required_with:builder|string|max:255',

            'builder.subquery' => 'nullable|array',
            'builder.subquery.query' => 'nullable|string|max:20000',
            'builder.subquery.columns' => 'nullable|array|max:200',

            'builder.joins' => 'nullable|array|max:5',
            'builder.joins.*.table' => 'required|string|max:255',
            'builder.joins.*.type' => 'nullable|string|max:16',
            'builder.joins.*.on' => 'nullable|array|max:5',
            'builder.joins.*.on.*.left_table' => 'nullable|string|max:255',
            'builder.joins.*.on.*.left' => 'required|string|max:255',
            'builder.joins.*.on.*.right' => 'required|string|max:255',

            'builder.metrics' => 'nullable|array|max:20',
            'builder.metrics.*.agg' => 'required|string|max:32',
            'builder.metrics.*.column' => 'nullable|string|max:255',

            'builder.metrics.*.table' => 'nullable|string|max:255',
            'builder.metrics.*.label' => 'nullable|string|max:255',

            'builder.metrics.*.target' => 'nullable|numeric',

            'builder.dimensions' => 'nullable|array|max:5',
            'builder.dimensions.*.column' => 'required|string|max:255',
            'builder.dimensions.*.table' => 'nullable|string|max:255',
            'builder.dimensions.*.grain' => 'nullable|string|max:16',
            'builder.dimensions.*.label' => 'nullable|string|max:255',

            'builder.filters' => 'nullable|array|max:20',
            'builder.filters.*.column' => 'required|string|max:255',
            'builder.filters.*.table' => 'nullable|string|max:255',
            'builder.filters.*.op' => 'required|string|max:16',
            'builder.filters.*.value' => 'nullable',

            'builder.sort' => 'nullable|array',
            'builder.sort.by' => 'nullable|string|max:16',
            'builder.sort.dir' => 'nullable|string|max:8',

            'builder.limit' => 'nullable|integer|min:1',

            'query' => 'required_without:builder|nullable|string|max:20000',
            'presentation' => 'nullable',
        ]);

        $presentation = $data['presentation'] ?? [];

        if (is_string($presentation)) {
            $presentation = trim($presentation) === '' ? [] : json_decode($presentation, true);

            if (!is_array($presentation)) {
                throw ValidationException::withMessages([
                    'presentation' => 'Оформление должно быть объектом JSON.',
                ]);
            }
        }

        return [
            'query' => (string) ($data['query'] ?? ''),
            'builder' => $data['builder'] ?? null,
            'presentation' => is_array($presentation) ? $presentation : [],
        ];
    }

    public function runDraft(Request $request, $dashboardId, $widgetId, ManualWidgetAuthor $author)
    {
        $dashboard = $this->findDashboard($request, $dashboardId);
        $widget = $this->findWidget($dashboard, $widgetId, ['widget.types', 'widgetType']);

        $data = $request->validate(['code' => 'required|string']);

        $dataSource = $this->requireDataSource($dashboard);

        $result = $author->runDraft($widget, $data['code'], $dataSource);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function saveCode(Request $request, $dashboardId, $widgetId, ManualWidgetAuthor $author)
    {
        $dashboard = $this->findDashboard($request, $dashboardId);
        $widget = $this->findWidget($dashboard, $widgetId, ['widget.types', 'widgetType']);

        $data = $request->validate(['code' => 'required|string']);

        $dataSource = $this->requireDataSource($dashboard);

        $widget->setRelation('dashboard', $dashboard);

        $result = $author->save($widget, $data['code'], $dataSource);

        $this->syncDashboardStatus($dashboard);

        event(new DashboardWidgetChanged($dashboard));

        return response()->json(
            $result + ['widget' => $this->present($widget->fresh(['widget.types', 'widgetType']))],

            $result['saved'] ? 200 : 422
        );
    }

    public function restoreCode(Request $request, $dashboardId, $widgetId, ManualWidgetAuthor $author)
    {
        $dashboard = $this->findDashboard($request, $dashboardId);
        $widget = $this->findWidget($dashboard, $widgetId, ['widget.types', 'widgetType']);

        $widget->setRelation('dashboard', $dashboard);

        $result = $author->restorePrevious($widget, $this->requireDataSource($dashboard));

        event(new DashboardWidgetChanged($dashboard));

        return response()->json(
            $result + ['widget' => $this->present($widget->fresh(['widget.types', 'widgetType']))],
            $result['saved'] ? 200 : 422
        );
    }

    private function present(DashboardWidget $widget): array
    {
        return [
            'id' => $widget->id,
            'dashboard_id' => $widget->dashboard_id,
            'widget_id' => $widget->widget_id,
            'widget_type_id' => $widget->widget_type_id,
            'title' => $widget->title,
            'instruction' => $widget->instruction,
            'position' => $widget->position,
            'status' => $widget->status,
            'origin' => $widget->origin,
            'content_mode' => $widget->content_mode,

            'query' => $this->queryOf($widget),

            'builder' => $widget->query_spec['builder'] ?? null,
            'presentation' => $widget->query_spec['presentation'] ?? null,
            'required_columns' => $this->requiredColumnsOf($widget),
            'slots' => $this->slotsOf($widget),
            'code' => $widget->code,
            'has_previous_code' => is_string($widget->code_previous) && trim($widget->code_previous) !== '',
            'last_error' => $widget->last_error,
            'last_run_at' => $widget->last_run_at,
            'updated_at' => $widget->updated_at,
            'widget' => $widget->widget ? [
                'id' => $widget->widget->id,
                'name' => $widget->widget->name,
                'types' => $widget->widget->types->map(fn (WidgetType $type) => [
                    'id' => $type->id,
                    'widget_id' => $type->widget_id,
                    'name' => $type->name,
                    'title' => $type->title,
                    'options' => $type->options ?? [],
                    'is_default' => $type->is_default,
                ])->values(),
            ] : null,
            'widget_type' => $widget->widgetType ? [
                'id' => $widget->widgetType->id,
                'widget_id' => $widget->widgetType->widget_id,
                'name' => $widget->widgetType->name,
                'options' => $widget->widgetType->options ?? [],
            ] : null,
        ];
    }

    private function resolveType(Widget $widget, ?int $typeId): ?WidgetType
    {
        if ($typeId) {
            $type = $widget->types->firstWhere('id', $typeId);

            if (!$type) {
                throw ValidationException::withMessages([
                    'widget_type_id' => 'Тип отрисовки не подходит этому виджету.',
                ]);
            }

            return $type;
        }

        return $widget->defaultType();
    }

    private function syncDashboardStatus(Dashboard $dashboard): void
    {
        $hasWidgets = DashboardWidget::query()
            ->where('dashboard_id', $dashboard->id)
            ->exists();

        $status = $hasWidgets ? 'completed' : 'empty';

        if ($dashboard->status !== $status) {
            $dashboard->status = $status;
            $dashboard->save();
        }
    }

    private function requireDataSource(Dashboard $dashboard)
    {
        $dataSource = $dashboard->resolveDataSource();

        if (!$dataSource || $dataSource->company_id !== $dashboard->company_id) {
            throw ValidationException::withMessages([
                'data_source_id' => 'У дашборда не задан источник данных.',
            ]);
        }

        return $dataSource;
    }

    private function findDashboard(Request $request, $id): Dashboard
    {
        return Dashboard::query()
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($id);
    }

    private function findWidget(Dashboard $dashboard, $widgetId, array $with = []): DashboardWidget
    {
        return DashboardWidget::query()
            ->with($with)
            ->where('dashboard_id', $dashboard->id)
            ->findOrFail($widgetId);
    }
}
