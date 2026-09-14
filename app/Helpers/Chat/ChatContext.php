<?php

namespace App\Helpers\Chat;

use App\Models\AiChat;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use App\Models\DataSourceGroup;
use App\Models\Widget;
use Illuminate\Support\Collection;

class ChatContext
{

    private const MAX_GROUPS = 25;

    public const MAX_TABLES_PER_REQUEST = 120;

    private array $focusedGroupIds = [];

    public ?DataSource $dataSource = null;

    public ?Dashboard $dashboard = null;

    public $dashboardWidgets;

    public $groups;

    public $widgetTypes;

    public $otherDashboards;

    public function __construct(
        public int $chatId,
        public ?int $dashboardId = null
    ) {

        $chat = AiChat::query()->find($chatId);

        $this->dataSource = $chat?->resolveDataSource(['type']);

        $workspaceId = $chat?->workspace_id;

        // Дашбордом считается только тот, что пользователь явно открыл (dashboardId
        // пришёл с фронтенда из URL). Если чат открыт просто в пространстве, без
        // конкретного дашборда, — считаем, что дашборда нет, даже если в пространстве
        // уже есть другие: иначе "создай дашборд" по ошибке маршрутизируется как
        // обновление чужого дашборда, который пользователь не открывал.
        $this->dashboard = $dashboardId
            ? Dashboard::query()->find($dashboardId)
            : null;

        $this->dashboardWidgets = $this->dashboard
            ? DashboardWidget::query()
                ->where('dashboard_id', $this->dashboard->id)
                ->with('widget.types', 'widgetType')
                ->orderBy('position')
                ->orderBy('id')
                ->get()
            : collect();

        $this->otherDashboards = $this->dashboardsOf($chatId, $workspaceId)
            ->when($this->dashboard, fn ($q) => $q->where('id', '!=', $this->dashboard->id))
            ->get(['id', 'name', 'status']);

        $this->groups = $this->dataSource
            ? DataSourceGroup::query()
                ->where('data_source_id', $this->dataSource->id)
                ->with(['tables' => fn ($q) => $q->orderBy('id')])
                ->orderBy('id')
                ->limit(self::MAX_GROUPS)
                ->get()
            : collect();

        $this->widgetTypes = Widget::query()
            ->where('is_ai_selectable', true)
            ->with('selectableTypes')
            ->get(['id', 'name', 'description']);
    }

    public function hasDataSource(): bool
    {
        return $this->dataSource !== null;
    }

    public function hasDashboard(): bool
    {
        return $this->dashboard !== null;
    }

    public function hasDashboardWithWidgets(): bool
    {
        return $this->dashboard !== null && $this->dashboardWidgets->isNotEmpty();
    }

    public function hasGroups(): bool
    {
        return $this->groups->isNotEmpty();
    }

    public function focusOnGroups(array $groupIds): void
    {
        $known = $this->groups->pluck('id');

        $this->focusedGroupIds = collect($groupIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $known->contains($id))
            ->unique()
            ->values()
            ->all();
    }

    public function focusedGroupIds(): array
    {
        return $this->focusedGroupIds;
    }

    public function groupsForSelection(): Collection
    {
        return $this->groups->map(fn (DataSourceGroup $group) => [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
        ])->values();
    }

    public function totalTablesCount(): int
    {
        return (int) $this->groups->sum(fn (DataSourceGroup $group) => $group->tables->count());
    }

    public function tablesForGroups(array $groupIds): array
    {
        $requested = collect($groupIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $known = $this->groups->whereIn('id', $requested->all())->keyBy('id');

        $tables = $known
            ->flatMap(fn (DataSourceGroup $group) => $group->tables->map(fn ($table) => [
                'name' => $table->name,
                'description' => $table->description,
                'role' => $table->role,
                'group' => $group->name,
            ]))
            ->values();

        return [
            'tables' => $tables->take(self::MAX_TABLES_PER_REQUEST)->all(),
            'unknown_groups' => $requested->diff($known->keys())->values()->all(),
            'truncated' => $tables->count() > self::MAX_TABLES_PER_REQUEST,
        ];
    }

    public function allTableNames(): array
    {
        return $this->groups
            ->flatMap(fn (DataSourceGroup $group) => $group->tables->pluck('name'))
            ->unique()
            ->values()
            ->all();
    }

    public function toArray(): array
    {
        return [
            'data_source' => $this->dataSource ? [
                'type' => $this->dataSource->type->name ?? null,
                'connected' => true,
            ] : [
                'connected' => false,
                'note' => 'Источник данных к этому чату не подключён — построить дашборд невозможно.',
            ],

            'data_groups' => $this->groups->map(function (DataSourceGroup $group) {
                $entry = [
                    'id' => $group->id,
                    'name' => $group->name,
                    'description' => $group->description,
                    'tables_count' => $group->tables->count(),
                ];

                if (in_array($group->id, $this->focusedGroupIds, true)) {
                    $entry['tables'] = $group->tables
                        ->take(self::MAX_TABLES_PER_REQUEST)
                        ->map(fn ($table) => [
                            'name' => $table->name,
                            'description' => $table->description,
                            'role' => $table->role,
                        ])
                        ->values()
                        ->all();
                }

                return $entry;
            })->values()->all(),

            'data_groups_total_tables' => $this->totalTablesCount(),

            'current_dashboard' => $this->dashboard ? [
                'id' => $this->dashboard->id,
                'name' => $this->dashboard->name,
                'status' => $this->dashboard->status,
                'widgets_count' => $this->dashboardWidgets->count(),
                'widgets' => $this->dashboardWidgets->map(fn (DashboardWidget $w) => [
                    'position' => $w->position,
                    'title' => $w->title,
                    'widget_type' => $w->widget?->name,
                'widget_view' => $w->widgetType?->name,
                    'status' => $w->status,
                    'tables' => $w->tables,
                    'what_it_shows' => $w->instruction,
                ])->values()->all(),
            ] : null,

            'other_dashboards' => $this->otherDashboards->map(fn (Dashboard $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'status' => $d->status,
            ])->values()->all(),

            'available_widget_types' => $this->widgetTypes->map(fn (Widget $w) => [
                'name' => $w->name,
                'description' => $w->description,

                'types' => $w->selectableTypes->map(fn ($t) => [
                    'type' => $t->name,
                    'title' => $t->title,
                    'when_to_use' => $t->description,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    private function dashboardsOf(int $chatId, ?int $workspaceId)
    {
        return Dashboard::query()->where(function ($query) use ($chatId, $workspaceId) {
            $query->where('chat_id', $chatId);

            if ($workspaceId) {
                $query->orWhere('workspace_id', $workspaceId);
            }
        });
    }

}
