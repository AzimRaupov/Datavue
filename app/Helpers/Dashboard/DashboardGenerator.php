<?php

namespace App\Helpers\Dashboard;

use App\Events\DashboardWidgetChanged;
use App\Helpers\Ai\DashboardAi;
use App\Helpers\DataSource\CodeTemplater;
use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\DataSource\SchemaOptions;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use App\Models\DataSourceGroup;
use App\Models\DataSourceTable;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Widget;
use App\Models\WidgetType;
use App\Helpers\Widget\WidgetCatalog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DashboardGenerator
{
    public $chat;

    public $message;

    public $widgets;

    public $storage;

    public $dashboard;

    public $tables;
    public $dbSchema;

    public $tasks;
    public $tasks_statuses;
    public $dataSource;
    public $connectionProviderRouter;
    public $plan;
    public $selectedTables = [];

    /**
     * Токен для авторизации сгенерированных Python-скриптов при обращении к API источника данных.
     * При необходимости замените на реальный источник токена (например, API-токен компании/чата).
     */
    public ?string $token = null;

    protected DashboardAi $dashboardGeneratorAi;

    public function __construct($chat_id, $message_id)
    {
        $this->chat = AiChat::query()->with('user', 'extractedData')->find($chat_id);
        $this->message = AiChatMessage::query()->find($message_id);

        // Источник привязан к чату полем ai_chats.data_source_id: одна и та же
        // база обслуживает несколько чатов, поэтому обратный поиск по
        // data_sources.chat_id больше не работает.
        $this->dataSource = $this->chat?->resolveDataSource();

        if (!$this->dataSource) {
            throw new RuntimeException("DataSource не найден для чата #{$chat_id}");
        }

        $this->storage = storage_path(
            'app/company/'.
            $this->chat->company_id.
            '/chats/'.
            $this->chat->id
        );

        // Только виджеты, реально готовые к использованию (подключённые на фронте) —
        // см. Widget::is_ai_selectable (например 'map' пока исключён).
        $this->widgets = Widget::query()
            ->where('is_ai_selectable', true)
            ->with(['types', 'selectableTypes'])
            ->get();
        $this->dashboard = Dashboard::query()->create([
            'chat_id' => $chat_id,
            'company_id' => $this->chat->company_id,
            'status' => 'empty',
        ]);

        $this->tasks_statuses = TaskStatus::query()
            ->pluck('id', 'name')
            ->toArray();
        $this->tasks = Task::query()
            ->pluck('id', 'name')
            ->toArray();

        $this->dashboardGeneratorAi = new DashboardAi($this->dataSource);
        $this->connectionProviderRouter = new ConnectionProviderRouter($this->dataSource->id);

        $this->fetchSchemaDb();
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function normalizeRow($row): array
    {
        if (is_object($row)) {
            return get_object_vars($row);
        }

        if (is_array($row)) {
            return $row;
        }

        return [];
    }

    /**
     * Единый формат ответа каждого шага.
     */
    private function result(bool $errors, string $message = '', array $extra = []): array
    {
        return array_merge(['errors' => $errors, 'message' => $message], $extra);
    }

    /**
     * Первый шаг выбора виджетов: сузить каталог до нужных семейств.
     *
     * Отдельный дешёвый вызов вместо того, чтобы вываливать в основной промпт
     * все 13 семейств со всеми вариантами. Ошибка на этом шаге не фатальна —
     * WidgetCatalog всё равно добавит базовые семейства, а при пустом ответе
     * отдаст каталог целиком.
     *
     * @return array<int, string>
     */
    private function defineWidgetFamilies(WidgetCatalog $catalog, array $scheme, string $text): array
    {
        // Для выбора семейства достаточно знать, какие есть таблицы и колонки:
        // связи, типы и количество строк тут только раздували бы промпт.
        $summary = [];

        foreach ($scheme as $tableName => $tableSchema) {
            $columns = array_keys($tableSchema['columns'] ?? []);
            $relations = array_keys($tableSchema['relations'] ?? []);

            $summary[$tableName] = array_values(array_merge($columns, $relations));
        }

        try {
            $response = $this->dashboardGeneratorAi->defineWidgetFamilies(
                $catalog->briefJson(),
                json_encode($summary, JSON_UNESCAPED_UNICODE),
                $text
            );

            $families = $response['content']['families'] ?? [];

            if (!is_array($families)) {
                $families = [];
            }

            Log::info('DashboardGenerator: widget families selected', [
                'families' => $families,
            ]);

            return $families;
        } catch (Throwable $e) {
            // Шаг вспомогательный: если он упал, работаем по полному каталогу.
            Log::warning('DashboardGenerator: widget family selection failed, using full catalog', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Сопоставляет выбранный ИИ тип с каталогом семейства.
     *
     * Модель может тип не указать или назвать несуществующий — виджет из-за этого
     * ломаться не должен, поэтому в обоих случаях откатываемся на тип по умолчанию.
     */
    private function resolveWidgetType(Widget $widget, ?string $typeName): ?WidgetType
    {
        $typeName = is_string($typeName) ? trim($typeName) : '';

        if ($typeName !== '') {
            $type = $widget->selectableTypes->firstWhere('name', $typeName);

            if ($type) {
                return $type;
            }

            Log::warning('DashboardGenerator: unknown widget type from AI, falling back to default', [
                'widget' => $widget->name,
                'type' => $typeName,
            ]);
        }

        return $widget->defaultType();
    }

    public function defineGroups(): array
    {
        try {
            $schemeGroups = DataSourceGroup::query()
                ->where('data_source_id', $this->dataSource->id)
                ->get(['id', 'name', 'description']);

            if ($schemeGroups->isEmpty()) {
                return $this->result(
                    true,
                    'For this data source no groups were found. Run DataSourceGrouping::handle()+save() first.'
                );
            }

            $response = $this->dashboardGeneratorAi->defineGroups(
                groups: $schemeGroups,
                text: $this->message->message
            );
            $groupsIds = $response['content']['groups'];

            $this->selectedTables = DataSourceTable::query()
                ->whereIn('data_source_group_id', $groupsIds)
                ->get();

            return $this->result(false, '', ['groups' => $groupsIds]);
        } catch (Throwable $e) {
            return $this->result(true, $e->getMessage());
        }
    }

    public function createPlan(): array
    {
        try {
            $tables = $this->selectedTables
                ->pluck('name')
                ->toArray();

            $scheme = $this->connectionProviderRouter->getSchema(
                tables: $tables,
                options: SchemaOptions::basic()
            );
            $schemeStr = json_encode($scheme, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            $result = $this->dashboardGeneratorAi->generatePlan($this->message->message, $schemeStr);
            $this->plan = $result['content'];

            return $this->result(false, '', ['plan' => $this->plan]);
        } catch (Throwable $e) {
            return $this->result(true, $e->getMessage());
        }
    }

    public function fetchSchemaDb()
    {
        $this->tables = $this->connectionProviderRouter->showTables();

        $dbSchema = [];

        foreach ($this->tables as $tableName) {
            $columns = $this->connectionProviderRouter->showColumns($tableName);

            $tableColumns = [];

            foreach ($columns as $column) {
                $columnName = $column['column_name'] ?? null;

                if (!$columnName) {
                    continue;
                }

                $tableColumns[$columnName] = [
                    'type' => $column['type'] ?? 'unknown',
                    'nullable' => $column['nullable'] ?? 'YES',
                    'key' => $column['key'] ?? '',
                    'default' => $column['default'] ?? null,
                ];
            }

            $dbSchema[$tableName] = $tableColumns;
        }

        $this->dbSchema = $dbSchema;
    }

    public function getDashboard()
    {
        return $this->dashboard;
    }

    public function generateWidgets(): array
    {
        try {
            $text = $this->message->message;

            $tables = $this->selectedTables
                ->pluck('name')
                ->toArray();

            // Роли таблиц: ['users' => 'fact', 'orders' => 'dimension', ...]
            $tableRoles = $this->selectedTables
                ->pluck('role', 'name')
                ->toArray();

            $scheme = $this->connectionProviderRouter->getSchema(
                tables: $tables,
                options: SchemaOptions::basic()
            );

            foreach ($scheme as $tableName => &$tableSchema) {
                $tableSchema['role'] = $tableRoles[$tableName] ?? null;
            }
            unset($tableSchema);

            // Каталог виджетов отдаём в два приёма: сначала короткий список семейств,
            // затем подробности только по выбранным. Иначе справочник занимает
            // больше половины промпта и вытесняет схему таблиц пользователя.
            $catalog = new WidgetCatalog($this->widgets);
            $families = $this->defineWidgetFamilies($catalog, $scheme, $text);
            $widgets = $catalog->detailedJson($families);

            $schemeStr = json_encode($scheme, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $response = $this->dashboardGeneratorAi->generateWidgets($schemeStr, $widgets, $text, $families);
            $generateWidgets = $response['content']['widgets'];

            // position проставляем сразу при создании, в том порядке, в котором
            // виджеты вернул ИИ. Раньше он оставался дефолтным (0) до генерации
            // контента, и фронт, сортирующий по position, тасовал плейсхолдеры
            // после каждого готового виджета.
            $position = 0;

            foreach ($generateWidgets as $list) {
                $widget = $this->widgets->where('name', $list['name'])->first();

                if (!$widget) {
                    continue;
                }

                DashboardWidget::query()->create([
                    'dashboard_id' => $this->dashboard->id,
                    'widget_id' => $widget->id,
                    'widget_type_id' => $this->resolveWidgetType($widget, $list['type'] ?? null)?->id,
                    'title' => $list['title'],
                    'instruction' => $list['instruction'],
                    'tables' => $list['tables'],
                    'position' => $position++,
                ]);
            }

            $this->dashboard->name = $response['content']['dashboard_name'];
            $this->dashboard->save();

            return $this->result(false, '', ['dashboard_name' => $this->dashboard->name]);
        } catch (Throwable $e) {
            return $this->result(true, $e->getMessage());
        }
    }
    /**
     * $onWidgetDone(DashboardWidget $widget, array $widgetResult, int $index, int $total) вызывается
     * после генерации каждого отдельного виджета — используется вызывающим кодом (Job), чтобы
     * пушить прогресс на фронт по мере готовности виджетов.
     *
     * Провал отдельного виджета НЕ считается ошибкой всего шага (errors=true) — виджет просто
     * помечается 'failed' внутри generateContentWidget() и позже может быть исправлен на этапе
     * ReviewWidgetsDashboard. errors=true здесь означает падение самого шага целиком (например,
     * не удалось получить список виджетов дашборда), а не отказ конкретного виджета.
     */
    public function generateContentToWidgets(?callable $onWidgetDone = null): array
    {
        try {
            // Порядок обхода фиксируем явно: без orderBy MySQL мог отдать виджеты
            // в произвольном порядке, и индекс не совпадал с их позицией на дашборде.
            $widgets_dash = DashboardWidget::query()->with('widget.types', 'widgetType')
                ->where('dashboard_id', $this->dashboard->id)
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->values();

            $results = [];
            $total = $widgets_dash->count();
            $failed = 0;

            foreach ($widgets_dash as $index => $widget) {
                $widget_tables = $widget->tables ?? [];
                $tables_scheme = $this->connectionProviderRouter->getSchema($widget_tables, SchemaOptions::detailed());

                $widgetResult = $this->generateContentWidget($widget, $tables_scheme);

                if (!empty($widgetResult['errors'])) {
                    $failed++;
                }

                $results[] = $widgetResult;

                if ($onWidgetDone) {
                    $onWidgetDone($widget, $widgetResult, $index, $total);
                }
            }

            return $this->result(false, '', [
                'widgets' => $results,
                'total' => $total,
                'failed' => $failed,
            ]);
        } catch (Throwable $e) {
            return $this->result(true, $e->getMessage());
        }
    }

    public function generateContentWidget($dashboard_widget, $tables_scheme): array
    {
        try {
            $type = $this->dataSource->type->name;

            $codeTemplater = new CodeTemplater($this->dataSource->id, $this->token);
            $codeTemplate = $codeTemplater->generateFullScript();
            $schemeStr = json_encode($tables_scheme, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            $mainBody = $this->dashboardGeneratorAi->generateContentWidget(
                $dashboard_widget,
                $schemeStr,
                $codeTemplate
            );

            $path = $this->storage.'/dashboard/widgets/'.$dashboard_widget->id.'/generated_script.py';

            File::ensureDirectoryExists(dirname($path));
            File::put($path, $mainBody);

            $dashboard_widget->code_path = $path;
            $dashboard_widget->status = 'active';
            $dashboard_widget->save();
            event(new DashboardWidgetChanged($this->dashboard));

            return $this->result(false, '', ['widget' => $dashboard_widget]);
        } catch (Throwable $e) {
            $dashboard_widget->status = 'failed';
            $dashboard_widget->save();


            return $this->result(true, $e->getMessage(), ['widget' => $dashboard_widget]);
        }
    }
}
