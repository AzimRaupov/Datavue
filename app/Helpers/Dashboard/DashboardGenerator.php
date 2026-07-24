<?php

namespace App\Helpers\Dashboard;

use App\Events\DashboardWidgetChanged;
use App\Helpers\Ai\DashboardAi;
use App\Helpers\DataSource\CodeTemplater;
use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\AiChatTask;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Widget;
use Illuminate\Support\Facades\File;
use RuntimeException;
use function Pest\Laravel\options;

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

        $this->dataSource = DataSource::query()->where('chat_id', $chat_id)->with('type', 'extracted')->first();

        if (!$this->dataSource) {
            throw new RuntimeException("DataSource не найден для чата #{$chat_id}");
        }

        $this->storage = storage_path(
            'app/company/'.
            $this->chat->company_id.
            '/chats/'.
            $this->chat->id
        );

        $this->widgets = Widget::all();
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
        return '"' . str_replace('"', '""', $identifier) . '"';
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
    public function createPlan()
    {
        $task = AiChatTask::query()->create([
            'chat_id' => $this->chat->id,
            'message_id' => $this->message->id,
            'task_id' => $this->tasks['dashboard_creating_plan'],
            'status_id' => $this->tasks_statuses['in_progress'],
        ]);
        $task->load(['status', 'task']);
        $this->dashboard->status = "generating_scheme";
        $this->dashboard->save();
        event(new DashboardWidgetChanged($this->dashboard));
        event(new \App\Events\MessageTasksChanged($this->message, $task, $this->dashboard->id));

        $scheme = $this->connectionProviderRouter->getSchema(
            tables: [],
            options: [
                'count_rows',
                'relations' => [
                    'column' => [
                        'type',
                    ],
                    'relation' => [
                        'table',
                    ],
                ],
            ]
        );
        $schemeStr = json_encode($scheme, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $result=$this->dashboardGeneratorAi->generatePlan($this->message->message,$schemeStr);
        $this->plan = $result['content'];

        $task->status_id = $this->tasks_statuses['completed'];
        $task->save();
        $task->load('status');
        event(new \App\Events\MessageTasksChanged($this->message, $task));

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

    public function generateWidgets()
    {
        $task = AiChatTask::query()->create([
            'chat_id' => $this->chat->id,
            'message_id' => $this->message->id,
            'task_id' => $this->tasks['detect_schema_dashboard'],
            'status_id' => $this->tasks_statuses['in_progress'],
        ]);
        $task->load(['status', 'task']);

        event(new \App\Events\MessageTasksChanged($this->message, $task, $this->dashboard->id));
        $text = $this->message->message;

        $widgetsList = $this->widgets->map(function ($widget) {
            return [
                'name' => $widget->name,
                'description' => $widget->description,
            ];
        });

        $widgets = json_encode($widgetsList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $tables = json_encode($this->tables, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        foreach ($this->plan['plans'] as $plan) {
            $planTablesScheme = $this->connectionProviderRouter->getSchema($plan["tables"],[
                'count_rows',
                'columns',
                'relations' => [
                    'column' => [
                        'type',
                        'nullable',
                        'key',
                        'default',
                    ],

                    'relation' => [
                        'column',
                        'table',
                        'confidence',
                        'match_rate',
                    ],
                ],
            ]);
            $planTablesSchemeStr = json_encode($planTablesScheme,JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $response = $this->dashboardGeneratorAi->generateWidget($plan['description'],$planTablesSchemeStr,$widgets);

            $widget = $response['content'];
            if($widget) {
                $widget['tables'] = $plan['tables'];
                $generateWidgets[] = $widget;
            }

        }

        foreach ($generateWidgets as $list) {
            $widget = $this->widgets->where('name', $list['widget_name'])->first();

            if (!$widget) {
                continue;
            }

            DashboardWidget::query()->create([
                'dashboard_id' => $this->dashboard->id,
                'widget_id' => $widget->id,
                'title' => $list['title'],
                'instruction' => $list['instruction'],
                'tables' => json_encode($list['tables']),
            ]);
        }

        $task->status_id = $this->tasks_statuses['completed'];
        $task->save();
        $task->load('status');
        event(new \App\Events\MessageTasksChanged($this->message, $task));

        $this->dashboard->name = $this->plan['dashboard_name'];
        $this->dashboard->status = "generating_widgets";
        $this->dashboard->save();
        event(new DashboardWidgetChanged($this->dashboard));
    }

    public function generateContentToWidgets()
    {
        $widgets_dash = DashboardWidget::query()->with('widget')
            ->where('dashboard_id', $this->dashboard->id)->get()
            ->values();

        $results = [];

        $task = AiChatTask::query()->create([
            'chat_id' => $this->chat->id,
            'message_id' => $this->message->id,
            'task_id' => $this->tasks['generate_widgets_dashboard'],
            'status_id' => $this->tasks_statuses['in_progress'],
        ]);
        $task->load(['status', 'task']);

        event(new \App\Events\MessageTasksChanged($this->message, $task));

        foreach ($widgets_dash as $index => $widget) {
            $widget_tables = json_decode($widget->tables, true) ?? [];

            $tables_scheme = $this->connectionProviderRouter->getSchema($widget_tables,[
                'count_rows',
                'columns',
                'relations' => [
                    'column' => [
                        'type',
                        'nullable',
                        'key',
                        'default',
                    ],

                    'relation' => [
                        'column',
                        'table',
                        'confidence',
                        'match_rate',
                    ],
                ],
            ]);
            $results[] = $this->generateContentWidget($widget, $index, $tables_scheme);
        }

        $task->status_id = $this->tasks_statuses['completed'];
        $task->save();
        $task->load('status');
        event(new \App\Events\MessageTasksChanged($this->message, $task));

        $this->dashboard->status = "completed";
        $this->dashboard->save();
        event(new DashboardWidgetChanged($this->dashboard));

        return $results;
    }

    public function generateContentWidget($dashboard_widget, $position, $tables_scheme)
    {
        $type = $this->dataSource->type->name;

        $codeTemplater = new CodeTemplater($this->dataSource->id, $this->token);
        $codeTemplate = $codeTemplater->generateFullScript();
        $schemeStr = json_encode($tables_scheme,JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);


        $mainBody = $this->dashboardGeneratorAi->generateContentWidget(
            $dashboard_widget,
            $schemeStr,
            $type,
            $codeTemplate
        );

        $path = $this->storage.'/dashboard/widgets/'.$dashboard_widget->id.'/generated_script.py';

        File::ensureDirectoryExists(dirname($path));

        File::put($path, $mainBody);

        $dashboard_widget->code_path = $path;
        $dashboard_widget->status = 'active';
        $dashboard_widget->position = $position;
        $dashboard_widget->save();

        event(new DashboardWidgetChanged($this->dashboard));

        return $dashboard_widget;
    }
}
