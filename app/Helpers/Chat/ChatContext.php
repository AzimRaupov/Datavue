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

    /**
     * Сколько имён таблиц показывать в обзоре группы.
     *
     * В контекст уходит только «витрина» группы — по ней агент понимает, о чём
     * группа, и запрашивает её состав инструментом. Раньше сюда выгружались все
     * таблицы всех групп: на источнике в 300 таблиц это несколько тысяч строк
     * промпта ещё до того, как агент вообще понял, о чём спрашивают.
     */
    private const PREVIEW_TABLES_PER_GROUP = 8;

    /** Потолок на размер ответа инструмента «таблицы групп». */
    public const MAX_TABLES_PER_REQUEST = 120;

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
                ->with('widget.types', 'widgetType')
                ->orderBy('position')
                ->orderBy('id')
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

    public function hasGroups(): bool
    {
        return $this->groups->isNotEmpty();
    }

    /**
     * Сколько всего таблиц разложено по группам источника.
     */
    public function totalTablesCount(): int
    {
        return (int) $this->groups->sum(fn (DataSourceGroup $group) => $group->tables->count());
    }

    /**
     * Состав выбранных групп — тот же приём, что и в генераторе дашборда:
     * сначала модель выбирает группы, и только их таблицы попадают в промпт.
     *
     * @param  array<int|string>  $groupIds
     * @return array{tables: array<int, array<string, mixed>>, unknown_groups: array<int, int>, truncated: bool}
     */
    public function tablesForGroups(array $groupIds): array
    {
        $requested = collect($groupIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        // Берём только группы этого источника — id из ответа модели доверять нельзя.
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

    /**
     * Все известные имена таблиц источника — запасной путь, когда группировка
     * ещё не выполнялась и выбирать не из чего.
     */
    public function allTableNames(): array
    {
        return $this->groups
            ->flatMap(fn (DataSourceGroup $group) => $group->tables->pluck('name'))
            ->unique()
            ->values()
            ->all();
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
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'tables_count' => $group->tables->count(),
                'tables_preview' => $group->tables
                    ->take(self::PREVIEW_TABLES_PER_GROUP)
                    ->pluck('name')
                    ->values()
                    ->all(),
            ])->values()->all(),

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
                // Варианты отрисовки внутри семейства — чтобы агент мог советовать
                // не только «столбчатую диаграмму», но и «горизонтальную».
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
}
