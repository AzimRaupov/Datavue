<?php

namespace App\Http\Controllers\Dashboard;

use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\DataSource\ReadOnlyQueryRunner;
use App\Helpers\DataSource\SourceSchema;
use App\Helpers\Widget\WidgetQueryComposer;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\PresentsWidgetContent;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\WidgetType;
use Illuminate\Http\Request;
use Throwable;

/**
 * Данные, которые нужны рабочему месту сборки дашборда, но не нужны его
 * просмотру: код виджетов, схема источника, пробный запрос.
 *
 * Отдельный контроллер, потому что и права другие: смотреть дашборд может
 * viewer, а собирать — только тот, кому разрешено его менять.
 */
class DashboardBuilderController extends Controller
{
    use PresentsWidgetContent;

    /**
     * Дашборд целиком для конструктора.
     */
    public function edit(Request $request, $id)
    {
        $dashboard = $this->findForCompany($request, $id);

        $widgets = DashboardWidget::query()
            ->with(['widget.types', 'widgetType'])
            ->where('dashboard_id', $dashboard->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $dataSource = $dashboard->resolveDataSource();

        return response()->json([
            'id' => $dashboard->id,
            'name' => $dashboard->name,
            'description' => $dashboard->description,
            'status' => $dashboard->status,
            'origin' => $dashboard->origin,
            'chat_id' => $dashboard->chat_id,
            'data_source' => $dataSource ? [
                'id' => $dataSource->id,
                'name' => $dataSource->name,
                'type' => $dataSource->type->name ?? null,
            ] : null,
            'widgets' => $widgets->map(fn (DashboardWidget $widget) => [
                'id' => $widget->id,
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
                    'scheme' => $widget->widget->scheme,
                    'scheme_description' => $widget->widget->scheme_description,
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
            ])->values(),
        ]);
    }

    /**
     * Таблицы и колонки источника — подсказка автору кода.
     *
     * Кэш на пять минут: showColumns ходит в базу клиента по каждой таблице,
     * а панель со схемой открывается на каждом виджете.
     */
    public function schema(Request $request, $id)
    {
        $dashboard = $this->findForCompany($request, $id);
        $dataSource = $dashboard->resolveDataSource();

        if (!$dataSource) {
            return response()->json(['message' => 'У дашборда не задан источник данных.'], 422);
        }

        try {
            $tables = SourceSchema::tables($dataSource);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Не удалось прочитать схему источника: ' . $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data_source' => ['id' => $dataSource->id, 'name' => $dataSource->name],
            'tables' => $tables,
            // Словарь конструктора: функции, округления дат и условия приходят
            // с сервера, чтобы панель не держала их копию, которая разъедется
            // с тем, что реально умеет сборщик запроса.
            'aggregates' => WidgetQueryComposer::AGGREGATES,
            'grains' => WidgetQueryComposer::GRAINS,
            'operators' => WidgetQueryComposer::OPERATORS,
            'default_limit' => WidgetQueryComposer::DEFAULT_LIMIT,
        ]);
    }

    /**
     * Пробный SELECT по источнику дашборда — подобрать запрос до того, как
     * он попадёт в код виджета.
     *
     * Тот же ReadOnlyQueryRunner, что и у чат-агента: один SELECT, без «;»,
     * с потолком строк. Ничего изменить этим запросом нельзя.
     */
    public function query(Request $request, $id)
    {
        $dashboard = $this->findForCompany($request, $id);

        $request->validate(['query' => 'required|string']);

        $dataSource = $dashboard->resolveDataSource();

        if (!$dataSource) {
            return response()->json(['message' => 'У дашборда не задан источник данных.'], 422);
        }

        $runner = new ReadOnlyQueryRunner(
            new ConnectionProviderRouter($dataSource->id)
        );

        $result = $runner->run($request->input('query'));

        if (!$result['ok']) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json([
            'rows' => $result['rows'],
            'row_count' => $result['row_count'],
            'truncated' => $result['truncated'],
        ]);
    }




    private function findForCompany(Request $request, $id): Dashboard
    {
        return Dashboard::query()
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($id);
    }
}
