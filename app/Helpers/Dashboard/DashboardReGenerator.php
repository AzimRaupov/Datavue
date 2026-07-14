<?php

namespace App\Helpers\Dashboard;

use App\Helpers\Ai\AIService;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\AiChatTask;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
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

    public $duckdb;
    public $tables;

    public array $operations = [];
    public ?Dashboard $newDashboard = null;

    public $tasks_statuses;
    public $tasks;
    public $message;
    public array $finalWidgets = [];

    public function __construct(
        int $dashboardId,
        int $chatId,
        int $messageId
    ) {
        $this->dashboard = Dashboard::findOrFail($dashboardId);
        $this->chat = AiChat::with('extractedData')->findOrFail($chatId);
        $this->message = AiChatMessage::find($messageId);
        $this->dashboardWidgets = DashboardWidget::query()
            ->where('dashboard_id', $dashboardId)
            ->orderBy('position')
            ->get();

        $this->widgets = Widget::all();
        $this->duckdb = new DuckDB($this->chat->extractedData->data_path);
        $this->tables = $this->duckdb->run("SHOW TABLES;");
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

        $system = "Ты AI агент аналитической платформы DataVue. Твоя задача — по запросу пользователя точно определить минимальный набор операций над дашбордом (add/update/move/delete), не создавая дублей и не теряя существующие виджеты, которые пользователь не просил менять.";

        $prompt = <<<PROMPT
Ты анализируешь запрос пользователя к дашборду DataVue.

Твоя задача — определить, какие изменения необходимо выполнить над существующим дашбордом, чтобы итоговый набор и порядок виджетов точно соответствовал запросу.

=== Текущие виджеты дашборда (в текущем порядке, поле position — их текущая позиция, нумерация с 0) ===
{$widgetsJson}

=== Все таблицы, доступные в базе данных ===
{$tablesJson}

=== Список доступных типов виджетов (используются в поле "widget_name") ===
{$this->availableWidgetsJson}

=== Запрос пользователя ===
"{$this->instruction}"

=== Допустимые типы операции ===
- add — создать новый виджет, которого сейчас нет на дашборде.
- update — изменить содержание/визуализацию/данные существующего виджета (виджет остаётся тем же по смыслу, но пересчитывается заново).
- move — переместить существующий виджет на новую позицию БЕЗ изменения его содержимого. Используй эту операцию, когда пользователь просит изменить только порядок/расположение/место виджетов на дашборде, ничего не меняя в их данных или визуализации.
- delete — удалить существующий виджет с дашборда.

=== Правила определения типа операции ===
1. Если пользователь просит изменить только порядок или расположение уже существующих виджетов (например "передвинь", "поставь первым", "поменяй местами", "должны быть в начале/в конце") — используй ТОЛЬКО "move" для этих виджетов. Никогда не используй "add" для перемещения — это создаст дубликат существующего виджета. Никогда не используй "update" для перемещения — это впустую пересоздаст контент виджета.
2. Используй "add" только тогда, когда на дашборде нет виджета, который бы уже показывал то, что просит пользователь.
3. Используй "update", когда существующий виджет нужно оставить (тот же смысл/место в дашборде), но изменить его данные, метрику, тип визуализации или формулировку.
4. Верни только те операции, которые реально требуются по запросу. Все остальные виджеты не упоминай вообще — они автоматически останутся на дашборде без изменений.

=== Правила по полям ===
5. Для "update", "move" и "delete" обязательно используй существующий id виджета из списка выше в поле widget_id.
6. Для "add" всегда указывай "widget_id": null.
7. Никогда не придумывай несуществующие widget_id — используй только те, что реально есть в списке текущих виджетов.
8. "widget_name" обязателен для "add" и должен быть строго одним из значений поля "name" в списке доступных типов виджетов. Для "update" указывай новый widget_name только если тип визуализации должен смениться, иначе оставь прежний. Для "delete" и "move" widget_name не нужен (null).
9. Каждый id существующего виджета должен участвовать не более чем в одной операции.
10. "instruction" заполняется только для "add" и "update" и должно описывать, как именно должен выглядеть виджет и что именно он показывает (без выдуманных полей схемы, без упоминания номера/позиции). Для "delete" и "move" instruction = null.
11. "title" обязателен для "add" и "update" — короткий, точный, человекочитаемый заголовок, который отражает реальное содержание виджета (конкретную метрику/срез данных), а не общие фразы вроде "Новый виджет" или "Виджет 1". Для "delete" и "move" title = null.
12. "tables" — только реально существующие и релевантные таблицы из схемы, нужен для "add" и "update". Для "delete" и "move" — пустой массив [].
13. "position" — целевая позиция виджета в ИТОГОВОМ дашборде после применения всех операций (нумерация с 0, учитывай как изменяемые/новые, так и все нетронутые виджеты вместе). Указывай "position" для "add", "update" и "move" всегда, исходя из смысла запроса (если порядок не важен — ставь позицию в конец списка). Для "delete" position = null.
14. Если ни один виджет не требует изменений — верни пустой массив [].

=== Формат ответа ===
Верни ТОЛЬКО валидный JSON-массив объектов, без текста и markdown до или после JSON.

[
  {
    "widget_id": 5,
    "operation_type": "update",
    "widget_name": "",
    "title": "Продажи по месяцам",
    "position": 2,
    "instruction": "Столбчатая диаграмма, сравнивающая объём продаж по месяцам",
    "tables": ["orders"]
  },
  {
    "widget_id": null,
    "operation_type": "add",
    "widget_name": "",
    "title": "Количество продаж по категориям",
    "position": 0,
    "instruction": "Карточки с количеством продаж в разрезе категорий товаров",
    "tables": ["orders", "products"]
  },
  {
    "widget_id": 3,
    "operation_type": "move",
    "widget_name": null,
    "title": null,
    "position": 1,
    "instruction": null,
    "tables": []
  },
  {
    "widget_id": 9,
    "operation_type": "delete",
    "widget_name": null,
    "title": null,
    "position": null,
    "instruction": null,
    "tables": []
  }
]
PROMPT;
        $response = (new AIService(
            responseFormat: 'json',
            tokens: 5000
        ))->ask($prompt, $system);

        $operations = $response['content'] ?? null;

        if (!is_array($operations)) {
            Log::error('DashboardGenerator: invalid AI response for determineChanges', [
                'dashboard_id' => $this->dashboard->id ?? null,
                'response' => $response,
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

        $currentWidgetJson = json_encode($currentWidget, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $targetTables = !empty($operation['tables']) ? $operation['tables'] : $currentTables;

        $tablesScheme = $this->duckdb->getSchema($targetTables);
        $tablesSchemeJson = json_encode($tablesScheme, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $widgetSchemaJson = json_encode($widget, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $system = <<<'TEXT'
Ты — специализированный генератор автономных Python-скриптов для аналитики данных платформы DataVue.
Твоя задача — переписать Python-код существующего виджета под новые требования: чистый, эффективный, рабочий код на основе DuckDB и Pandas.
TEXT;

        $prompt = <<<PROMPT
Целевая схема виджета (тип, формат вывода):
{$widgetSchemaJson}

Схема доступных таблиц DuckDB, релевантных виджету:
{$tablesSchemeJson}

Текущий виджет дашборда (для контекста, что было раньше):
{$currentWidgetJson}

Что нужно изменить (инструкция):
{$operation['instruction']}

ОБЯЗАТЕЛЬНАЯ СТРУКТУРА СКРИПТА:
1. Импорт модулей: `duckdb`, `pandas as pd`, `json`, `sys`, `argparse`.
2. Парсинг единственного аргумента `--path` (через sys.argv или argparse). Других аргументов быть не должно.
3. Подключение к базе данных через `duckdb.connect()`.
4. Получение DataFrame через `.df()`, финальная подгонка под JSON-структуру.
5. Вывод итогового JSON в stdout через `print(json.dumps(..., ensure_ascii=False))`.

ВАЖНО:
- Используй только реально существующие таблицы и поля из приведённой схемы. Не выдумывай поля.
- Если нужных данных нет — сформируй пустой результат, соответствующий целевой схеме.
- Можно использовать любые стандартные библиотеки Python и pandas.
- Никаких комментариев в коде.
- Никакого markdown (без ```).
- Итоговый вывод скрипта должен строго соответствовать целевой схеме виджета "$widget->name".

Ответ строго валидный JSON-объект, без пояснений и markdown:
{"python_code": ""}
PROMPT;

        $response = (new AIService(
            responseFormat: 'json',
            tokens: 8000,
        ))->ask($prompt, $system);

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
        $tablesScheme = $this->duckdb->getSchema($operation['tables'] ?? []);
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
