<?php

namespace App\Helpers\Dashboard;

use App\Helpers\Ai\AIService;
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
use App\Helpers\DuckDB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DashboardReGenerator
{
    public string $instruction;

    public Dashboard $dashboard;
    public AiChat $chat;
    public $dashboardWidgets;
    public $widgets;

    private string $availableWidgetsJson;
    public $connectionProviderRouter;
    public $tables;

    public array $operations = [];
    public ?Dashboard $newDashboard = null;
    public $tasks_statuses;
    public $tasks;
    public $message;
    public array $finalWidgets = [];
    private DashboardAi $dashboardReGeneratorAi;
    public $dataSource;
   public $dbSchema;

   public $codeTemplate;
    public function __construct(
        int $dashboardId,
        int $chatId,
        int $messageId
    ) {
        $this->dashboard = Dashboard::findOrFail($dashboardId);
        $this->chat = AiChat::with('extractedData')->findOrFail($chatId);
        $this->message = AiChatMessage::find($messageId);
        $this->dataSource = DataSource::query()->where('chat_id', $chatId)->with('type', 'extracted')->first();

        $this->dashboardWidgets = DashboardWidget::query()
            ->where('dashboard_id', $dashboardId)
            ->orderBy('position')
            ->get();

        $this->widgets = Widget::all();
        $this->availableWidgetsJson = json_encode(
            $this->widgets
                ->map(fn($widget) => [
                    'name' => $widget->name,
                    'scheme_description' => $widget->scheme_description,
                ])
                ->toArray(),
            JSON_UNESCAPED_UNICODE
        );

        $this->tasks_statuses = TaskStatus::query()
            ->pluck('id', 'name')
            ->toArray();
        $this->tasks = Task::query()
            ->pluck('id', 'name')
            ->toArray();
        $this->connectionProviderRouter = new ConnectionProviderRouter($this->dataSource->id);
        $this->tables=$this->connectionProviderRouter->showTables();
        $this->dashboardReGeneratorAi = new DashboardAi($this->dataSource);
        $this->codeTemplate = new CodeTemplater($this->dataSource->id);
    }
    public function fetchSchemaDb(?array $tables = null)
    {
        // Если таблицы не переданы — получаем все таблицы
        $tables = $tables ?? $this->connectionProviderRouter->showTables();

        $dbSchema = [];

        foreach ($tables as $tableName) {
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

        return $dbSchema;
    }
    public function determineChanges(string $instruction): void
    {
        $task = AiChatTask::query()->create([
            'chat_id' => $this->chat->id,
            'message_id'=>$this->message->id,
            'task_id'=>$this->tasks["determine_changes"],
            'status_id'=>$this->tasks_statuses["in_progress"]
        ]);
        $task->load(['status', 'task']);

        event(new \App\Events\MessageTasksChanged($this->message,$task,null));

        $this->instruction = $instruction;

        $widgetsJson = json_encode(
            $this->dashboardWidgets
                ->map(fn($widget) => [
                    'id' => $widget->id,
                    'position' => $widget->position,
                    'title' => $widget->title,
                    'instruction' => $widget->instruction,
                    'widget_name' => $widget->widget?->name,
                    'tables' => json_decode($widget->tables, true) ?? [],
                ])
                ->values()
                ->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );

        $tablesJson = json_encode($this->tables, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $resultDefine = $this->dashboardReGeneratorAi->defineChanges($widgetsJson,$tablesJson,$this->availableWidgetsJson,$this->instruction);
        $operations = $resultDefine['content'] ?? null;

        if (!is_array($operations)) {
            Log::error('DashboardGenerator: invalid AI response for determineChanges', [
                'dashboard_id' => $this->dashboard->id ?? null,
                'response' => $resultDefine,
            ]);
            $operations = [];
        }

        $this->operations = $operations;

        $task->status_id = $this->tasks_statuses["completed"];
        $task->save();
        $task->load('status');
        event(new \App\Events\MessageTasksChanged($this->message,$task,null));

    }


    public function applyChanges(): Dashboard
    {
        $updatedIds = [];
        $deletedIds = [];
        $movedIds = [];

        $task = AiChatTask::query()->create([
            'chat_id' => $this->chat->id,
            'message_id'=>$this->message->id,
            'task_id'=>$this->tasks["updating_dashboard"],
            'status_id'=>$this->tasks_statuses["in_progress"]
        ]);
        $task->load(['status', 'task']);

        event(new \App\Events\MessageTasksChanged($this->message,$task,null));


        foreach ($this->operations as $operation) {
            $type = $operation['operation_type'] ?? null;
            $widgetId = $operation['widget_id'] ?? null;

            if (!$widgetId) {
                continue;
            }

            if ($type === 'update') {
                $updatedIds[] = (int) $widgetId;
            } elseif ($type === 'delete') {
                $deletedIds[] = (int) $widgetId;
            } elseif ($type === 'move') {
                $movedIds[] = (int) $widgetId;
            }
        }


        $untouched = $this->dashboardWidgets
            ->reject(fn($w) => in_array($w->id, $updatedIds, true)
                || in_array($w->id, $deletedIds, true)
                || in_array($w->id, $movedIds, true))
            ->sortBy('position')
            ->values()
            ->map(fn($w) => [
                'source' => 'untouched',
                'title' => $w->title,
                'instruction' => $w->instruction,
                'widget_name' => $w->widget?->name,
                'tables' => json_decode($w->tables, true) ?? [],
                'python_code' => ($w->code_path && file_exists($w->code_path))
                    ? file_get_contents($w->code_path)
                    : null,
                'position' => $w->position,
            ])
            ->all();


        $inserts = [];
        foreach ($this->operations as $operation) {
            $type = $operation['operation_type'] ?? null;

            if ($type === 'update') {
                $result = $this->reGenerateWidget($operation);
                if ($result) {
                    $result['source'] = 'update';
                    $inserts[] = $result;
                }
            } elseif ($type === 'add') {
                $result = $this->addWidget($operation);
                if ($result) {
                    $result['source'] = 'add';
                    $inserts[] = $result;
                }
            } elseif ($type === 'move') {
                $result = $this->moveWidget($operation);
                if ($result) {
                    $result['source'] = 'move';
                    $inserts[] = $result;
                }
            }
        }

        usort($inserts, function ($a, $b) {
            $posA = $a['position'] ?? PHP_INT_MAX;
            $posB = $b['position'] ?? PHP_INT_MAX;
            return $posA <=> $posB;
        });

        $final = $untouched;

        foreach ($inserts as $item) {
            $position = $item['position'];

            if ($position === null || $position < 0) {
                $position = count($final);
            }

            $position = min($position, count($final));

            array_splice($final, $position, 0, [$item]);
        }

        foreach ($final as $index => &$item) {
            $item['position'] = $index;
        }
        unset($item);

        $this->finalWidgets = $final;

        $this->newDashboard = Dashboard::query()->create([
            'chat_id' => $this->chat->id,
            'name' => $this->dashboard->name,
            'company_id' => $this->chat->company_id,
        ]);

        foreach ($this->finalWidgets as $item) {
            $this->persistWidget($this->newDashboard, $item);
        }


        $task->status_id = $this->tasks_statuses["completed"];
        $task->save();
        $task->load('status');
        event(new \App\Events\MessageTasksChanged($this->message,$task,$this->newDashboard->id));

        return $this->newDashboard;

    }


    private function moveWidget(array $operation): ?array
    {
        $dashboardWidget = $this->dashboardWidgets->firstWhere('id', $operation['widget_id'] ?? null);

        if (!$dashboardWidget) {
            Log::error('DashboardReGenerator: dashboard widget not found for move', [
                'widget_id' => $operation['widget_id'] ?? null,
            ]);
            return null;
        }

        return [
            'title' => $dashboardWidget->title,
            'instruction' => $dashboardWidget->instruction,
            'widget_name' => $dashboardWidget->widget?->name,
            'tables' => json_decode($dashboardWidget->tables, true) ?? [],
            'python_code' => ($dashboardWidget->code_path && file_exists($dashboardWidget->code_path))
                ? file_get_contents($dashboardWidget->code_path)
                : null,
            'position' => $operation['position'] ?? null,
        ];
    }

    private function persistWidget(Dashboard $dashboard, array $item): DashboardWidget
    {
        $widget = Widget::query()->where('name', $item['widget_name'])->first();

        $codePath = null;
        if (!empty($item['python_code'])) {
            $codePath = $this->savePythonCode($dashboard->id, $item['python_code']);
        }

        return DashboardWidget::query()->create([
            'dashboard_id' => $dashboard->id,
            'widget_id' => $widget?->id,
            'title' => $item['title'],
            'instruction' => $item['instruction'],
            'tables' => json_encode($item['tables'] ?? [], JSON_UNESCAPED_UNICODE),
            'code_path' => $codePath,
            'position' => $item['position'],
        ]);
    }

    private function savePythonCode(int $dashboardId, string $code): string
    {
        $relativePath = "dashboards/{$dashboardId}/" . Str::uuid() . '.py';
        Storage::put($relativePath, $code);

        return Storage::path($relativePath);
    }

    public function reGenerateWidget(array $operation): ?array
    {

        $dashboardWidget = $this->dashboardWidgets->firstWhere('id', $operation['widget_id'] ?? null);

        if (!$dashboardWidget) {
            Log::error('DashboardReGenerator: dashboard widget not found', [
                'widget_id' => $operation['widget_id'] ?? null,
            ]);
            return null;
        }

        $widgetName = $operation['widget_name'] ?? $dashboardWidget->widget?->name;

        $widget = Widget::query()
            ->where('name', $widgetName)
            ->select(['name', 'scheme', 'scheme_description'])
            ->first();

        if (!$widget) {
            Log::error('DashboardReGenerator: widget type not found', [
                'widget_name' => $widgetName,
            ]);
            return null;
        }

        $currentTables = json_decode($dashboardWidget->tables, true) ?? [];

        $currentWidget = [
            'title' => $dashboardWidget->title,
            'widget_name' => $dashboardWidget->widget?->name,
            'instruction' => $dashboardWidget->instruction,
            'tables' => $currentTables,
            'python_code' => null,
        ];

        if ($dashboardWidget->code_path && file_exists($dashboardWidget->code_path)) {
            $currentWidget['python_code'] = file_get_contents($dashboardWidget->code_path);
        }
        $fullCode = $this->codeTemplate->getLibraries()."\n";
        $fullCode.= $this->codeTemplate->getQueryTemplate()."\n";
        $fullCode.= file_get_contents($dashboardWidget->code_path)."\n";
        $fullCode.= $this->codeTemplate->getFooter();
        $currentWidgetJson = json_encode($currentWidget, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $targetTables = !empty($operation['tables']) ? $operation['tables'] : $currentTables;

        $tablesScheme = $this->fetchSchemaDb($targetTables);
        $tablesSchemeJson = json_encode($tablesScheme, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $widgetSchemaJson = json_encode($widget, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $response = $this->dashboardReGeneratorAi->reGenerateWidget($widgetSchemaJson,$tablesSchemeJson,$currentWidgetJson,$operation['instruction'],$widget->name,$fullCode);

        $result = $response['content'] ?? null;
        if (!is_array($result) || empty($result['python_code'])) {
            Log::error('DashboardReGenerator: invalid AI response for reGenerateWidget', [
                'widget_id' => $dashboardWidget->id,
                'response' => $response,
            ]);
            return null;
        }

        $pythonCode = trim((string) $result['python_code']);
        $pythonCode = preg_replace('/^```(?:python)?\s*/i', '', $pythonCode);
        $pythonCode = preg_replace('/\s*```$/', '', $pythonCode);

        return [
            'title' => $operation['title'] ?? $dashboardWidget->title,
            'instruction' => $operation['instruction'] ?? $dashboardWidget->instruction,
            'widget_name' => $widgetName,
            'tables' => $targetTables,
            'python_code' => $pythonCode,
            'position' => $operation['position'] ?? null,
        ];
    }

    public function addWidget($operation): ?array
    {
        $tablesScheme = $this->fetchSchemaDb($operation['tables'] ?? []);
        $tablesJson = json_encode($tablesScheme, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $widget = Widget::query()
            ->where('name', $operation['widget_name'])
            ->first();

        if (!$widget) {
            Log::error('DashboardReGenerator: widget type not found for add', [
                'widget_name' => $operation['widget_name'] ?? null,
            ]);
            return null;
        }

        $system = <<<'TEXT'
Ты — специализированный генератор автономных Python-скриптов для аналитики данных.
Твоя задача — написать чистый, эффективный и рабочий код, сочетающий DuckDB и Python.

СТРОГИЕ ТЕХНИЧЕСКИЕ ОГРАНИЧЕНИЯ:
1. Скрипт должен принимать ровно один аргумент командной строки: --path (путь к файлу базы данных DuckDB). Добавлять другие аргументы (даты, лимиты, флаги) категорически запрещено.
TEXT;

        $prompt = <<<TEXT
Напиши автономный Python-скрипт, который агрегирует данные из DuckDB и форматирует их в нужный вид.

ОБЯЗАТЕЛЬНАЯ СТРУКТУРА СКРИПТА:
1. Импорт модулей: `duckdb`, `pandas as pd`, `json`, `sys`, `argparse`.
2. Парсинг единственного аргумента `--path` (через sys.argv или argparse).
3. Подключение к базе данных через `duckdb.connect()`.
5. Получение DataFrame через `.df()`, финальная подгонка под JSON-структуру.
6. Вывод итогового JSON в stdout через `print(json.dumps(..., ensure_ascii=False))`.

ВАЖНО:
- Используй только реально существующие таблицы и поля из доступной схемы.
- Если нужных данных или таблиц для выполнения инструкции нет, сформируй пустой результат, соответствующий целевой схеме.

ДОСТУПНАЯ СХЕМА DUCKDB:
{$tablesJson}

ИНСТРУКЦИЯ ПО КАК ДОЛЖНО БЫТ:
{$operation['instruction']}

ЦЕЛЕВАЯ JSON СХЕМА ВЫВОДА:
{$widget->scheme}

ОПИСАНИЕ ПОЛЕЙ JSON ВЫХОДА:
{$widget->scheme_description}

ТЕХНИЧЕСКИЕ ПРАВИЛА:
- Аргумент базы передается строго как --path=
- Никаких комментариев в коде.
- Можно использовать любые системный библатеки python и pandas
- Никакого markdown (не используй блоки ```).
- Только чистый, готовый к исполнению Python-код.
TEXT;

        $response = (new AIService(
            responseFormat: 'text',
            tokens: 5000,
        ))->ask($prompt, $system);

        $pythonCode = trim((string) $response['content']);
        $pythonCode = preg_replace('/^```(?:python)?\s*/i', '', $pythonCode);
        $pythonCode = preg_replace('/\s*```$/', '', $pythonCode);
        $pythonCode = preg_replace('/["\']\s*$/', '', $pythonCode);

        return [
            'title' => $operation['title'],
            'instruction' => $operation['instruction'],
            'widget_name' => $operation['widget_name'],
            'tables' => $operation['tables'] ?? [],
            'python_code' => $pythonCode,
            'position' => $operation['position'] ?? null,
        ];
    }
}
