<?php

namespace App\Helpers\Dashboard;

use App\Events\DashboardWidgetChanged;
use App\Helpers\Ai\DashboardAi;
use App\Helpers\DataSource\CodeTemplater;
use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\DataSource\SchemaOptions;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\AiChatTask;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

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
    public $groups;

    public $codeTemplate;

    private const OP_UPDATE_STRUCT = 'update_struct';
    private const OP_UPDATE_VIEW   = 'update_view';
    private const OP_ADD           = 'add';
    private const OP_DELETE        = 'delete';
    public $selectedGroupsTables;

    // списки созданных/обновлённых виджетов
    // listAddWidgets: элементы - объекты DashboardWidget (новые, source = add)
    // listUpdateWidgets: элементы - массивы ['widget' => DashboardWidget, 'dashboard_widget_id' => int, 'old_instruction' => string|null] (source = update_struct)
    public array $listAddWidgets = [];
    public array $listUpdateWidgets = [];

    // готовые к отправке в ИИ массивы
    public array $addWidgetsPayload = [];
    public array $updateWidgetsPayload = [];
    public $storage;

    // списки DashboardWidget, подготовленные в generateInstruction()
    // и потребляемые generatingWidgets() / reGeneratingWidgets().
    // Заполняются в $this->, методы больше не принимают их как аргументы.
    public array $generateNewWidgets = [];
    public array $reGenerateWidgets = [];

    public function __construct(
        int $dashboardId,
        int $chatId,
        int $messageId
    ) {
        $this->dashboard = Dashboard::findOrFail($dashboardId);
        $this->chat = AiChat::with('extractedData')->findOrFail($chatId);
        $this->message = AiChatMessage::find($messageId);
        // См. DashboardGenerator: источник ищется через чат, а не наоборот.
        $this->dataSource = $this->chat?->resolveDataSource();

        $this->dashboardWidgets = DashboardWidget::query()
            ->where('dashboard_id', $dashboardId)
            ->with('widget.types', 'widgetType')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        // Только виджеты, реально готовые к использованию (подключённые на фронте) —
        // см. Widget::is_ai_selectable (например 'map' пока исключён).
        $this->widgets = Widget::query()
            ->where('is_ai_selectable', true)
            ->with(['types', 'selectableTypes'])
            ->get();

        $this->storage = storage_path(
            'app/company/'.
            $this->chat->company_id.
            '/chats/'.
            $this->chat->id
        );
        $this->tasks_statuses = TaskStatus::query()
            ->pluck('id', 'name')
            ->toArray();
        $this->tasks = Task::query()
            ->pluck('id', 'name')
            ->toArray();
        $this->connectionProviderRouter = new ConnectionProviderRouter($this->dataSource->id);
        $this->tables = $this->connectionProviderRouter->showTables();
        $this->dashboardReGeneratorAi = new DashboardAi($this->dataSource);
        $this->codeTemplate = new CodeTemplater($this->dataSource->id);
        $this->groups = DataSourceGroup::query()->where('data_source_id', $this->dataSource->id)->get();

    }


    public function determineChanges(string $instruction): void
    {
        $task = AiChatTask::query()->create([
            'chat_id' => $this->chat->id,
            'message_id' => $this->message->id,
            'task_id' => $this->tasks["determine_changes"],
            'status_id' => $this->tasks_statuses["in_progress"]
        ]);
        $task->load(['status', 'task']);

        event(new \App\Events\MessageTasksChanged($this->message, $task, null));

        $this->instruction = $instruction;

        $widgetsJson = json_encode(
            $this->dashboardWidgets
                ->map(fn($widget) => [
                    'id' => $widget->id,
                    'position' => $widget->position,
                    'title' => $widget->title,
                    'instruction' => $widget->instruction,
                    'widget_name' => $widget->widget?->name,
                    'widget_type' => $widget->widgetType?->name,
                    'tables' => $widget->tables ?? [],
                ])
                ->values()
                ->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );

        $groups = json_encode($this->groups->select('id', 'name'), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        // Здесь решается, ЧТО менять, поэтому каталог идёт без форм данных —
        // они нужны только при написании инструкций и генерации кода.
        $widgets = $this->widgetCatalogJson();

        $data = [
            'dashboard_name' => $this->dashboard->name,
            'dashboard_widgets' => $widgetsJson,
            'groups' => $groups,
            'widgets' => $widgets,
            'text' => $instruction
        ];

        $resultDefine = $this->dashboardReGeneratorAi->defineChanges($data);

        $operations = $resultDefine['content']['operations'] ?? null;
        $this->selectedGroupsTables= $resultDefine['content']['groups_tables'] ?? null;
        if (!is_array($operations)) {
            Log::error('DashboardGenerator: invalid AI response for determineChanges', [
                'dashboard_id' => $this->dashboard->id ?? null,
                'response' => $resultDefine,
            ]);
            $operations = [];
        }

        $this->operations = $operations;

        // Единственная запись о том, ЧТО именно решено поменять. Без неё разбор
        // жалобы «задачи выполнились, а дашборд прежний» упирается в пустоту:
        // по логам не отличить «модель ничего не вернула» от «операции не
        // применились».
        Log::info('DashboardReGenerator: operations decided', [
            'dashboard_id' => $this->dashboard->id ?? null,
            'message_id' => $this->message->id ?? null,
            'instruction' => $instruction,
            'operations' => array_map(fn ($operation) => [
                'type' => $operation['operation_type'] ?? null,
                'widget_id' => $operation['widget_id'] ?? null,
                'position' => $operation['position'] ?? null,
                'title' => $operation['title'] ?? null,
            ], $operations),
        ]);

        $task->status_id = $this->tasks_statuses["completed"];
        $task->save();
        $task->load('status');
        event(new \App\Events\MessageTasksChanged($this->message, $task, null));
    }

    public function prepareAiPayload(): void
    {
        $this->addWidgetsPayload = collect($this->listAddWidgets)
            ->map(function (DashboardWidget $widget) {
                return [
                    'id'=>$widget->id,
                    'title' => $widget->title,
                    'description' => $widget->instruction,
                    'widget_name' => $widget->widget?->name,
                    'widget_type' => $widget->widgetType?->name,
                ];
            })
            ->values()
            ->all();

        $this->updateWidgetsPayload = collect($this->listUpdateWidgets)
            ->map(function (array $entry) {
                /** @var DashboardWidget $widget */
                $widget = $entry['widget'];
                return [
                    'id'=>$entry['id'],
                    'old_instruction' => $entry['old_instruction'] ?? '',
                    'old_widget_name'=>$entry['old_widget_name'] ?? '',
                    'description_update' => $widget->instruction,
                    'widget_name'=> $widget->widget?->name,
                    'widget_type'=> $widget->widgetType?->name
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Только определяет инструкции/таблицы для виджетов по ответу ИИ
     * и раскладывает их по $this->generateNewWidgets / $this->reGenerateWidgets.
     * Сама генерация кода (generatingWidgets()/reGeneratingWidgets()) сюда больше
     * не вызывается — вызывающий код должен вызвать их отдельно после этого метода.
     */
    public function generateInstruction()
    {

        $task = AiChatTask::query()->create([
            'chat_id' => $this->chat->id,
            'message_id' => $this->message->id,
            'task_id' => $this->tasks["generating_widget_instructions"],
            'status_id' => $this->tasks_statuses["in_progress"]
        ]);
        $task->load(['status', 'task']);

        event(new \App\Events\MessageTasksChanged($this->message, $task, null));

        $tables = DataSourceTable::query()
            ->whereIn('data_source_group_id', $this->selectedGroupsTables)
            ->pluck('name')
            ->toArray();
        $schema = $this->connectionProviderRouter->getSchema($tables, SchemaOptions::basic());

        $this->prepareAiPayload();
        // Здесь пишутся инструкции виджетов, поэтому форма данных нужна:
        // от неё зависит, что инструкция обязана описать (например третью
        // метрику для пузырьков).
        $widgets = (new WidgetCatalog($this->widgets))->detailedJson();
        $schemaStr = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $listAddWidgets = json_encode($this->addWidgetsPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $listUpdateWidgets = json_encode($this->updateWidgetsPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $data=[
            'schema' => $schemaStr,
            'widgets' => $widgets,
            'listAddWidgets' => $listAddWidgets,
            'listUpdateWidgets' => $listUpdateWidgets,
        ];
        $response=$this->dashboardReGeneratorAi->generateInstruction($data);

        $content = $response['content'] ?? null;

        if (!is_array($content)) {
            Log::error('DashboardReGenerator: invalid AI response for generateInstruction', [
                'dashboard_id' => $this->dashboard->id ?? null,
                'response' => $response,
            ]);
            $content = [];
        }

        // сбрасываем перед новым наполнением, чтобы не накапливать данные
        // от предыдущих вызовов generateInstruction() в рамках одного объекта
        $this->generateNewWidgets = [];
        $this->reGenerateWidgets = [];

        foreach ($content as $listWidget) {
            $widget = DashboardWidget::query()->find($listWidget['widget_id'] ?? null);

            if (!$widget) {
                Log::warning('DashboardReGenerator: dashboard widget not found in generateInstruction', [
                    'widget_id' => $listWidget['widget_id'] ?? null,
                ]);
                continue;
            }

            if(isset($listWidget['impossible'])){
                $widget->status="failed";
            }
            else{
                $widget->instruction=$listWidget['instruction'] ?? $widget->instruction;
                $widget->tables = $listWidget['tables'] ?? $widget->tables;

            }
            $widget->save();

            if(($listWidget['operation'] ?? null)=="add") {
                $this->generateNewWidgets[]=$widget;
            }
            else if(($listWidget['operation'] ?? null)=="update") {
                $this->reGenerateWidgets[]=$widget;
            }
        }

        $task->status_id = $this->tasks_statuses["completed"];
        $task->save();
        $task->load('status');
        event(new \App\Events\MessageTasksChanged($this->message, $task, null));
    }


    public function applyChanges(): Dashboard
    {
        $updatedIds = [];
        $deletedIds = [];
        $movedIds = [];

        $task = AiChatTask::query()->create([
            'chat_id' => $this->chat->id,
            'message_id' => $this->message->id,
            'task_id' => $this->tasks["updating_dashboard"],
            'status_id' => $this->tasks_statuses["in_progress"]
        ]);
        $task->load(['status', 'task']);

        event(new \App\Events\MessageTasksChanged($this->message, $task, null));

        foreach ($this->operations as $operation) {
            $type = $operation['operation_type'] ?? null;
            $widgetId = $operation['widget_id'] ?? null;

            if ($type === self::OP_ADD) {
                continue;
            }

            if (!$widgetId) {
                Log::warning('DashboardReGenerator: operation without widget_id skipped', [
                    'operation' => $operation,
                ]);
                continue;
            }

            switch ($type) {
                case self::OP_UPDATE_STRUCT:
                    $updatedIds[] = (int) $widgetId;
                    break;
                case self::OP_DELETE:
                    $deletedIds[] = (int) $widgetId;
                    break;
                case self::OP_UPDATE_VIEW:
                    $movedIds[] = (int) $widgetId;
                    break;
                default:
                    Log::warning('DashboardReGenerator: unknown operation_type skipped', [
                        'operation_type' => $type,
                        'widget_id' => $widgetId,
                    ]);
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
                // Вариант отрисовки обязателен и здесь: без него persistWidget
                // подставлял виджету вариант семейства по умолчанию, и любая
                // регенерация молча превращала кольцо в круг, а горизонтальные
                // столбцы — в вертикальные у виджетов, которых никто не просил
                // менять.
                'widget_type' => $w->widgetType?->name,
                'tables' => $w->tables ?? [],
                'python_code' => ($w->code_path && file_exists($w->code_path))
                    ? file_get_contents($w->code_path)
                    : null,
                'position' => $w->position,
                'status' => $w->status ?? 'active',
            ])
            ->all();


        $inserts = [];
        foreach ($this->operations as $operation) {
            $type = $operation['operation_type'] ?? null;

            if ($type === self::OP_UPDATE_STRUCT) {

                $result = $this->updateWidget($operation);
                if ($result) {
                    $result['source'] = 'update_struct';
                    $inserts[] = $result;
                }
            } elseif ($type === self::OP_ADD) {
                $result = $this->addWidget($operation);
                if ($result) {
                    $result['source'] = 'add';
                    $inserts[] = $result;
                }
            } elseif ($type === self::OP_UPDATE_VIEW) {
                $result = $this->moveWidget($operation);
                if ($result) {
                    $result['source'] = 'update_view';
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

            // не даём вылезти за границы массива
            $position = min($position, count($final));

            array_splice($final, $position, 0, [$item]);
        }


        foreach ($final as $index => &$item) {
            $item['position'] = $index;
        }
        unset($item);

        $this->finalWidgets = $final;

        DB::transaction(function () {
            $this->newDashboard = Dashboard::query()->create([
                'chat_id' => $this->chat->id,
                'name' => $this->dashboard->name,
                'company_id' => $this->chat->company_id,
                'status' => 'empty'
            ]);

            foreach ($this->finalWidgets as $item) {
                $this->persistWidget($this->newDashboard, $item);
            }
        });

        $task->status_id = $this->tasks_statuses["completed"];
        $task->save();
        $task->load('status');
        event(new \App\Events\MessageTasksChanged($this->message, $task, $this->newDashboard->id));

        // Генерация инструкций/кода виджетов (AI-вызовы, запись файлов) намеренно вынесена
        // из этого метода наружу — см. DashboardReGeneratorJob::handle(), который вызывает
        // generateInstruction()/generatingWidgets()/reGeneratingWidgets() явно и оборачивает
        // их в общий try/catch с корректной обработкой ошибок.
        return $this->newDashboard;
    }


    public function reGeneratingWidgets(): void
    {
        if (empty($this->reGenerateWidgets)) {
            return;
        }

        $task = AiChatTask::query()->create([
            'chat_id' => $this->chat->id,
            'message_id' => $this->message->id,
            'task_id' => $this->tasks["updating_dashboard"],
            'status_id' => $this->tasks_statuses["in_progress"]
        ]);
        $task->load(['status', 'task']);

        event(new \App\Events\MessageTasksChanged($this->message, $task, null));
        foreach ($this->reGenerateWidgets as $dashboard_widget) {

            if (!is_file($dashboard_widget->code_path)) {
                $dashboard_widget->status = 'failed';
                $dashboard_widget->save();

                continue;
            }

            $code = file_get_contents($dashboard_widget->code_path);

            if ($code === false || trim($code) === '') {
                $dashboard_widget->status = 'failed';
                $dashboard_widget->save();

                continue;
            }

            $widget = Widget::query()->find($dashboard_widget->widget_id);

            if (!$widget) {
                $dashboard_widget->status = 'failed';
                $dashboard_widget->save();

                continue;
            }

            $fullCode = $this->codeTemplate->getLibraries() . "\n";
            $fullCode .= $this->codeTemplate->getQueryTemplate() . "\n";
            $fullCode .= $code . "\n";
            $fullCode .= $this->codeTemplate->getFooter();
            $scheme = $this->connectionProviderRouter->getSchema(
                $dashboard_widget->tables,
                SchemaOptions::basic()
            );

            $schemeStr = json_encode(
                $scheme,
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            );

            $data = [
                'scheme' => $schemeStr,
                'instruction' => $dashboard_widget->instruction,
                'widget_name' => $widget->name,
                'code' => $fullCode,
                'widget_scheme' => $widget->scheme,
                'widget_scheme_description' => $widget->scheme_description,
            ];

            $response = $this->dashboardReGeneratorAi->reGenerateWidget($data);

            $codePath = $this->storage
                . '/dashboard/widgets/'
                . $dashboard_widget->id
                . '/generated_script.py';

            $directory = dirname($codePath);

            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            if (
                !empty($response['content']['python_code']) &&
                is_string($response['content']['python_code'])
            ) {
                file_put_contents(
                    $codePath,
                    $response['content']['python_code']
                );

                $dashboard_widget->code_path = $codePath;
                $dashboard_widget->status = 'active';
            } else {
                $dashboard_widget->status = 'failed';
            }

            $dashboard_widget->save();

            event(new DashboardWidgetChanged($this->newDashboard));
        }

        $task->status_id = $this->tasks_statuses["completed"];
        $task->save();
        $task->load('status');
        event(new \App\Events\MessageTasksChanged($this->message, $task));
    }

    /**
     * Генерирует код для новых виджетов.
     * Список виджетов берётся из $this->generateNewWidgets (заполняется в generateInstruction()).
     */
    public function generatingWidgets()
    {
        if (empty($this->generateNewWidgets)) {
            return;
        }

        $task = AiChatTask::query()->create([
            'chat_id' => $this->chat->id,
            'message_id' => $this->message->id,
            'task_id' => $this->tasks["generate_widgets_dashboard"],
            'status_id' => $this->tasks_statuses["in_progress"]
        ]);
        $task->load(['status', 'task']);

        event(new \App\Events\MessageTasksChanged($this->message, $task, null));

        $codeTemplate=$this->codeTemplate->generateFullScript();
        foreach ($this->generateNewWidgets as $dashboard_widget) {
            if($dashboard_widget->instruction) {

                $scheme = $this->connectionProviderRouter->getSchema(
                    $dashboard_widget->tables,
                    SchemaOptions::basic()
                );
                $schemeStr = json_encode(
                    $scheme,
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                );

                $codePath = $this->storage
                    . '/dashboard/widgets/'
                    . $dashboard_widget->id
                    . '/generated_script.py';
                $data = [
                    'scheme' => $schemeStr,
                    'codeTemplate' => $codeTemplate,
                    'instruction' => $dashboard_widget->instruction,
                ];
                $mainBody = $this->dashboardReGeneratorAi->generateContentWidget($dashboard_widget,$schemeStr,$codeTemplate);

                File::ensureDirectoryExists(dirname($codePath));
                File::put($codePath, $mainBody);
                $dashboard_widget->code_path = $codePath;
                $dashboard_widget->status = 'active';
                $dashboard_widget->save();

            }
            event(new DashboardWidgetChanged($this->dashboard));

        }
        $task->status_id = $this->tasks_statuses["completed"];
        $task->save();
        $task->load('status');
        event(new \App\Events\MessageTasksChanged($this->message, $task));
    }

    public function updateWidget(array $operation): ?array
    {
        $widgetDashboard = DashboardWidget::query()->with('widget.types', 'widgetType')->find($operation['widget_id'] ?? null);

        if (!$widgetDashboard) {
            Log::error('DashboardReGenerator: dashboard widget not found for update_struct', [
                'widget_id' => $operation['widget_id'] ?? null,
            ]);
            return null;
        }

        $currentFamily = $widgetDashboard->widget?->name;
        $newFamily = $operation['widget_name'] ?? $currentFamily;

        // Прежний вариант отрисовки имеет смысл только внутри своего семейства:
        // при смене bar → pie тип "column" уже ничего не значит, и подставлять
        // его нельзя — семейство само выберет вариант по умолчанию.
        $carriedType = $newFamily === $currentFamily
            ? $widgetDashboard->widgetType?->name
            : null;

        return [
            'title' => $operation['title'] ?? $widgetDashboard->title,
            'instruction' => $operation['operation_description'] ?? ($widgetDashboard->instruction ?? ''),
            'widget_name' => $newFamily,
            'widget_type' => $operation['widget_type'] ?? $carriedType,
            'tables' => $operation['tables'] ?? ($widgetDashboard->tables ?? []),
            'python_code' => ($widgetDashboard->code_path && file_exists($widgetDashboard->code_path))
                ? file_get_contents($widgetDashboard->code_path)
                : null,
            'position' => $operation['position'] ?? $widgetDashboard->position ?? 0,
            'status' => 'draft',
            // id исходного (старого) dashboard_widget, который редактировался
            'dashboard_widget_id' => $widgetDashboard->id,
            // старая инструкция до правки (для payload в ИИ)
            'old_instruction' => $widgetDashboard->instruction,
            'old_widget_name'=>$widgetDashboard->widget?->name
        ];
    }

    private function moveWidget(array $operation): ?array
    {
        $dashboardWidget = $this->dashboardWidgets->firstWhere('id', $operation['widget_id'] ?? null);

        if (!$dashboardWidget) {
            Log::error('DashboardReGenerator: dashboard widget not found for update_view', [
                'widget_id' => $operation['widget_id'] ?? null,
            ]);
            return null;
        }

        return [
            'title' => $operation['title'] ?? $dashboardWidget->title,
            'instruction' => $dashboardWidget->instruction,
            'widget_name' => $dashboardWidget->widget?->name,
            'widget_type' => $dashboardWidget->widgetType?->name,
            'tables' => $dashboardWidget->tables ?? [],
            'python_code' => ($dashboardWidget->code_path && file_exists($dashboardWidget->code_path))
                ? file_get_contents($dashboardWidget->code_path)
                : null,
            'position' => $operation['position'] ?? $dashboardWidget->position ?? 0,
            'status' => $dashboardWidget->status ?? 'active',
        ];
    }

    /**
     * Каталог виджетов для промптов регенерации.
     *
     * Тот же компактный вид, что и при генерации: форма данных json-примером,
     * без прозаических описаний схемы. Полный каталог занимал бы больше половины
     * промпта и вытеснял описание текущих виджетов, которые как раз и правим.
     */
    private function widgetCatalogJson(): string
    {
        return (new WidgetCatalog($this->widgets))->compactJson();
    }

    /**
     * Сопоставляет выбранный ИИ вариант отрисовки с каталогом семейства.
     * Неизвестный или не указанный тип — не повод ломать виджет, берём вариант
     * семейства по умолчанию.
     */
    private function resolveWidgetType(?Widget $widget, ?string $typeName): ?WidgetType
    {
        if (!$widget) {
            return null;
        }

        $typeName = is_string($typeName) ? trim($typeName) : '';

        if ($typeName !== '') {
            $type = $widget->selectableTypes()->where('name', $typeName)->first();

            if ($type) {
                return $type;
            }

            Log::warning('DashboardReGenerator: unknown widget type from AI, falling back to default', [
                'widget' => $widget->name,
                'type' => $typeName,
            ]);
        }

        return $widget->defaultType();
    }

    private function persistWidget(Dashboard $dashboard, array $item): DashboardWidget
    {
        $widget = Widget::query()->where('name', $item['widget_name'])->first();

        if (!$widget) {
            // ИИ мог вернуть несуществующее имя виджета (галлюцинация) — логируем, чтобы
            // это не потерялось молча: dashboard_widget будет создан с widget_id=null и
            // сразу помечен failed вместо того, чтобы выглядеть валидным виджетом без типа.
            Log::warning('DashboardReGenerator: unknown widget_name from AI, widget_id will be null', [
                'widget_name' => $item['widget_name'] ?? null,
                'dashboard_id' => $dashboard->id,
            ]);
            $item['status'] = 'failed';
        }

        $codePath = null;
        if (!empty($item['python_code'])) {
            $codePath = $this->savePythonCode($dashboard->id, $item['python_code']);
        }

        $result = DashboardWidget::query()->create([
            'dashboard_id' => $dashboard->id,
            'widget_id' => $widget?->id,
            'widget_type_id' => $this->resolveWidgetType($widget, $item['widget_type'] ?? null)?->id,
            'title' => $item['title'],
            'instruction' => $item['instruction'],
            // Без json_encode: кодированием занимается модель. Ручной вызов
            // здесь давал двойное кодирование, и tables читались строкой.
            'tables' => $item['tables'] ?? [],
            'code_path' => $codePath,
            'position' => $item['position'],
            'status' => $item['status'] ?? 'draft',
        ]);

        // подгружаем связь widget, чтобы widget->name был доступен без доп. запроса позже
        if ($widget) {
            $result->setRelation('widget', $widget);
        }

        // $result уже содержит id (проставляется после create())
        if ($item['source'] === 'add') {
            $this->listAddWidgets[] = $result;
        } elseif ($item['source'] === 'update_struct') {
            $this->listUpdateWidgets[] = [
                'id'=>$result->id,
                'widget' => $result,
                'dashboard_widget_id' => $item['dashboard_widget_id'] ?? null,
                'old_widget_name' => $item['old_widget_name'] ?? null,
                'old_instruction' => $item['old_instruction'] ?? null,
            ];
        }

        return $result;
    }

    private function savePythonCode(int $dashboardId, string $code): string
    {
        $relativePath = "dashboards/{$dashboardId}/" . Str::uuid() . '.py';
        Storage::put($relativePath, $code);

        return Storage::path($relativePath);
    }

    public function addWidget(array $operation): ?array
    {
        $widgetName = $operation['widget_name'] ?? null;

        if (!$widgetName) {
            Log::error('DashboardReGenerator: widget_name is required for add operation', [
                'operation' => $operation,
            ]);
            return null;
        }

        return [
            'title' => $operation['title'] ?? $widgetName,
            'instruction' => $operation['operation_description'] ?? '',
            'widget_name' => $widgetName,
            'widget_type' => $operation['widget_type'] ?? null,
            'tables' => $operation['tables'] ?? [],
            'python_code' => null, // генерация кода временно отключена
            'position' => $operation['position'] ?? null,
            'status' => 'draft',
        ];
    }
}
