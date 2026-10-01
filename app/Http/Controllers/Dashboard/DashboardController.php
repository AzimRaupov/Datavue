<?php

namespace App\Http\Controllers\Dashboard;

use App\Helpers\Widget\ManualWidgetAuthor;
use App\Http\Controllers\Controller;
use App\Models\AiChat;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use App\Models\WidgetType;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{

    public function index(Request $request)
    {
        $dashboards = Dashboard::query()
            ->where('company_id', $request->user()->company_id)
            ->withCount('widgets')
            ->with([
                'dataSource:id,name',
                'chat:id,title',
            ])
            ->latest('id')
            ->get();

        $this->fillSourcesFromChats($dashboards);

        return response()->json($dashboards);
    }

    public function show(Request $request, $id)
    {

        $dashboard = Dashboard::query()
            ->where('company_id', $request->user()->company_id)
            ->with([
                'widgets' => function ($query) {
                    $query->select(
                        'id',
                        'dashboard_id',
                        'widget_id',
                        'widget_type_id',
                        'title',
                        'position',
                        'status',

                        'query_spec',

                        'updated_at'

                    )->orderBy('position')->orderBy('id');
                },
                'widgets.widget' => function ($query) {
                    $query->select('id', 'name');
                },

                'widgets.widget.types' => function ($query) {
                    $query->select('id', 'widget_id', 'name', 'options', 'is_default');
                },
                'widgets.widgetType' => function ($query) {
                    $query->select('id', 'widget_id', 'name', 'options');
                },
            ])
            ->findOrFail($id);

        return response()->json($dashboard);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',

            'workspace_id' => [
                'required_without_all:chat_id,data_source_id',
                'nullable',
                Rule::exists('workspaces', 'id')->where('company_id', $user->company_id),
            ],
            'chat_id' => [
                'nullable',

                Rule::exists('ai_chats', 'id')->where('company_id', $user->company_id),
            ],

            'data_source_id' => [
                'required_without_all:chat_id,workspace_id',
                'nullable',
                Rule::exists('data_sources', 'id')->where('company_id', $user->company_id),
            ],
        ]);

        $workspace = !empty($data['workspace_id'])
            ? Workspace::query()->ofCompany($user->company_id)->find($data['workspace_id'])
            : null;

        $dashboard = Dashboard::query()->create([
            'company_id' => $user->company_id,
            'workspace_id' => $workspace?->id,
            'created_by' => $user->id,
            'chat_id' => $data['chat_id'] ?? null,

            'data_source_id' => $data['data_source_id'] ?? $workspace?->data_source_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => 'empty',

            'origin' => Dashboard::ORIGIN_MANUAL,
        ]);

        return response()->json($dashboard, 201);
    }

    public function update(Request $request, $id)
    {
        $dashboard = $this->findForCompany($request, $id);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
        ]);

        $dashboard->fill($data)->save();

        return response()->json($dashboard);
    }

    public function updateWidgets(Request $request, $id, ManualWidgetAuthor $author)
    {
        $dashboard = $this->findForCompany($request, $id);

        $data = $request->validate([
            'widgets' => 'required|array|min:1',
            'widgets.*.id' => 'required|integer',
            'widgets.*.widget_type_id' => 'required|integer|exists:widget_types,id',
        ]);

        $widgets = DashboardWidget::query()
            ->with(['widget.types', 'widgetType'])
            ->where('dashboard_id', $dashboard->id)
            ->whereIn('id', collect($data['widgets'])->pluck('id'))
            ->get()
            ->keyBy('id');

        $types = WidgetType::query()
            ->whereIn('id', collect($data['widgets'])->pluck('widget_type_id'))
            ->get()
            ->keyBy('id');

        $updated = 0;
        $changed = [];

        DB::transaction(function () use ($data, $widgets, $types, &$updated, &$changed) {
            foreach ($data['widgets'] as $row) {
                $widget = $widgets->get($row['id']);
                $type = $types->get($row['widget_type_id']);

                if (!$widget || !$type) {
                    continue;
                }

                if ($type->widget_id !== $widget->widget_id) {
                    throw ValidationException::withMessages([
                        'widgets' => 'Тип отрисовки не подходит этому виджету.',
                    ]);
                }

                if ($widget->widget_type_id !== $type->id) {
                    $widget->widget_type_id = $type->id;
                    $widget->setRelation('widgetType', $type);
                    $widget->save();
                    $updated++;
                    $changed[] = $widget;
                }
            }
        });

        $this->rebuildBuilderWidgets($dashboard, $changed, $author);

        return response()->json([
            'success' => true,
            'updated' => $updated,
            'message' => $updated
                ? 'Изменения сохранены.'
                : 'Менять было нечего.',
        ]);
    }

    public function destroy(Request $request, $id, ManualWidgetAuthor $author)
    {
        $dashboard = $this->findForCompany($request, $id);

        foreach ($dashboard->widgets()->get() as $widget) {
            $widget->setRelation('dashboard', $dashboard);
            $author->deleteFiles($widget);
        }

        $dashboard->widgets()->delete();
        $dashboard->delete();

        return response()->json(['message' => 'Дашборд удалён.']);
    }

    private function rebuildBuilderWidgets(Dashboard $dashboard, array $widgets, ManualWidgetAuthor $author): void
    {
        if ($widgets === []) {
            return;
        }

        $dataSource = $dashboard->resolveDataSource();

        if (!$dataSource || $dataSource->company_id !== $dashboard->company_id) {
            return;
        }

        foreach ($widgets as $widget) {
            $author->rebuildForType($widget, $dataSource);
        }
    }

    private function fillSourcesFromChats($dashboards): void
    {
        $chatIds = $dashboards
            ->whereNull('data_source_id')
            ->pluck('chat_id')
            ->filter()
            ->unique();

        if ($chatIds->isEmpty()) {
            return;
        }

        $sourceIdByChat = AiChat::query()
            ->whereIn('id', $chatIds)
            ->pluck('data_source_id', 'id');

        $sources = DataSource::query()
            ->whereIn('id', $sourceIdByChat->filter()->unique()->values())
            ->get(['id', 'name'])
            ->keyBy('id');

        foreach ($dashboards as $dashboard) {
            if ($dashboard->data_source_id || !$dashboard->chat_id) {
                continue;
            }

            $source = $sources->get($sourceIdByChat->get($dashboard->chat_id));

            if ($source) {
                $dashboard->setRelation('dataSource', $source);
            }
        }
    }

    private function findForCompany(Request $request, $id): Dashboard
    {
        return Dashboard::query()
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($id);
    }
}
