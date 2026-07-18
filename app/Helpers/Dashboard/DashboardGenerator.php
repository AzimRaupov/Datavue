<?php

namespace App\Helpers\Dashboard;

use App\Events\DashboardWidgetChanged;
use App\Helpers\Ai\Dashboard\DashboardGeneratorAi;
use App\Helpers\DuckDB;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\AiChatTask;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Widget;
use Illuminate\Support\Facades\File;

class DashboardGenerator
{
    public $chat;

    public $message;

    public $widgets;

    public $storage;

    public $dashboard;

    public $tables;
    public $duckdb;
    public $dbSchema;

    public $tasks;
    public $tasks_statuses;

    protected DashboardGeneratorAi $dashboardGeneratorAi;

    public function __construct($chat_id, $message_id)
    {
        $this->chat = AiChat::query()->with('user', 'extractedData')->find($chat_id);
        $this->message = AiChatMessage::query()->find($message_id);

        $this->storage = storage_path(
            'app/company/'.
            $this->chat->company_id.
            '/chats/'.
            $this->chat->id
        );

        $this->duckdb = new DuckDB($this->chat->extractedData->data_path);

        $this->widgets = Widget::all();
        $this->dashboard = Dashboard::query()->create([
            'chat_id' => $chat_id,
            'company_id' => $this->chat->company_id,
            'status'=>'generating',
        ]);

        $this->tasks_statuses = TaskStatus::query()
            ->pluck('id', 'name')
            ->toArray();
        $this->tasks = Task::query()
            ->pluck('id', 'name')
            ->toArray();

        $this->dashboardGeneratorAi = new DashboardGeneratorAi();

        $this->fetchSchemaDb();
    }

    public function fetchSchemaDb()
    {
        $this->tables = $this->duckdb->run("SHOW TABLES;");

        $dbSchema = [];

        foreach ($this->tables as $table) {
            $tableName = $table['name'] ?? $table['table_name'] ?? null;

            if ($tableName) {
                $rawColumns = $this->duckdb->run("DESCRIBE " . $tableName . ";");

                $tableColumns = [];

                foreach ($rawColumns as $column) {
                    $columnName = $column['column_name'] ?? $column['Field'] ?? null;

                    if ($columnName) {
                        $tableColumns[$columnName] = [
                            'type' => $column['column_type'] ?? $column['Type'] ?? 'unknown',
                            'nullable' => $column['null'] ?? $column['Null'] ?? 'YES',
                            'key' => $column['key'] ?? $column['Key'] ?? '',
                            'default' => $column['default'] ?? $column['Default'] ?? null,
                        ];
                    }
                }

                $dbSchema[$tableName] = $tableColumns;
            }
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

        event(new \App\Events\MessageTasksChanged($this->message, $task));

        $text = $this->message->message;
        $widgetsList = $this->widgets->select(['name', 'description']);
        $widgets = json_encode($widgetsList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $tables = json_encode($this->tables, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $response = $this->dashboardGeneratorAi->generateWidgets($tables, $widgets, $text);
        $generateWidgets = $response['content'];

        foreach ($generateWidgets as $list) {
            $widget = $this->widgets->where('name', $list['name'])->first();

            if (! $widget) {
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

        event(new DashboardWidgetChanged($this->dashboard));
    }

    public function generateContentToWidgets()
    {
        $widgets_dash = DashboardWidget::query()->with('widget')
            ->where('dashboard_id', $this->dashboard->id)->get();

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

            $tables_scheme = collect($this->dbSchema)->only($widget_tables)->toArray();
            $results[] = $this->generateContentWidget($widget, $index, $tables_scheme);
        }

        $task->status_id = $this->tasks_statuses['completed'];
        $task->save();
        $task->load('status');
        event(new \App\Events\MessageTasksChanged($this->message, $task));

        return $results;
    }

    public function generateContentWidget($dashboard_widget, $position, $tables_scheme)
    {
        $pythonCode = $this->dashboardGeneratorAi->generateContentWidget($dashboard_widget, $position, $tables_scheme);

        $path = $this->storage.'/dashboard/widgets/'.$dashboard_widget->id.'/generated_script.py';

        File::ensureDirectoryExists(dirname($path));

        File::put($path, $pythonCode);

        $dashboard_widget->code_path = $path;
        $dashboard_widget->status = 'active';
        $dashboard_widget->position = $position;
        $dashboard_widget->save();

        event(new DashboardWidgetChanged($this->dashboard));

        return $dashboard_widget;
    }
}
