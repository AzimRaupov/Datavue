<?php

namespace App\Helpers\Chat;

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use App\Models\DataSourceGroup;
use App\Models\Widget;

/**
 * Единый сборщик контекста чата для AI.
 *
 * Раньше роутеру задач передавались только title+instruction виджетов, поэтому
 * модель физически не могла ответить на вопросы вида «какие у меня виджеты»,
 * «что порекомендуешь добавить», «какие данные доступны» — и от безысходности
 * выбирала перегенерацию дашборда на любой запрос со словом «добавить».
 *
 * Этот класс собирает всё, что агент должен знать о текущем состоянии:
 * источник данных, смысловые группы таблиц, текущий дашборд с виджетами,
 * другие дашборды чата и каталог доступных типов виджетов.
 */
class ChatContext
{
    /** Ограничения, чтобы промпт не разрастался на больших базах. */
    private const MAX_GROUPS = 25;
    private const MAX_TABLES_PER_GROUP = 40;

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
        $this->dataSource = DataSource::query()
            ->where('chat_id', $chatId)
            ->with('type')
            ->first();

        $this->dashboard = $dashboardId
            ? Dashboard::query()->find($dashboardId)
            : Dashboard::query()->where('chat_id', $chatId)->latest('id')->first();

        $this->dashboardWidgets = $this->dashboard
            ? DashboardWidget::query()
                ->where('dashboard_id', $this->dashboard->id)
                ->with('widget')
                ->orderBy('position')
                ->get()
            : collect();

        $this->otherDashboards = Dashboard::query()
            ->where('chat_id', $chatId)
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

        // Только те типы, что реально готовы к использованию — тот же фильтр,
        // что применяют генераторы, чтобы агент не советовал виджет, который
        // потом невозможно создать.
        $this->widgetTypes = Widget::query()
            ->where('is_ai_selectable', true)
            ->get(['name', 'description']);
    }

    public function hasDataSource(): bool
    {
        return $this->dataSource !== null;
    }

    public function hasDashboard(): bool
    {
        return $this->dashboard !== null;
    }

    /**
     * Компактное представление контекста для подстановки в промпт.
     */
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

            'data_groups' => $this->groups->map(fn (DataSourceGroup $group) => [
                'name' => $group->name,
                'description' => $group->description,
                'tables' => $group->tables
                    ->take(self::MAX_TABLES_PER_GROUP)
                    ->map(fn ($table) => [
                        'name' => $table->name,
                        'description' => $table->description,
                        'role' => $table->role,
                    ])
                    ->values()
                    ->all(),
            ])->values()->all(),

            'current_dashboard' => $this->dashboard ? [
                'id' => $this->dashboard->id,
                'name' => $this->dashboard->name,
                'status' => $this->dashboard->status,
                'widgets_count' => $this->dashboardWidgets->count(),
                'widgets' => $this->dashboardWidgets->map(fn (DashboardWidget $w) => [
                    'position' => $w->position,
                    'title' => $w->title,
                    'widget_type' => $w->widget?->name,
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
}
