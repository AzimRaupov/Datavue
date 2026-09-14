<?php

namespace App\Helpers\Task;

use App\Events\MessageTasksChanged;
use App\Helpers\Ai\ChatAgentAi;
use App\Helpers\Ai\DefineTaskAi;
use App\Helpers\Ai\IntentClassifier;
use App\Helpers\Chat\ChatContext;
use App\Helpers\DataSource\DataSourceGrouping;
use App\Jobs\ChatExportJob;
use App\Jobs\DashboardGeneratorJob;
use App\Jobs\DashboardReGeneratorJob;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\AiChatTask;
use App\Models\DataSource;
use App\Models\IntentSample;
use App\Models\Task;
use App\Models\TaskStatus;
use Illuminate\Support\Facades\Log;
use Throwable;

class RouterTask
{
    public $messages;
    public $chat;
    public $currentMessage;

    public $current_task;
    public $statuses;
    public $tasks;
    public $task_list;
    public $dashboardId;
    public $resultDefine;

    private ?string $clarification = null;

    public $userId;
    public $dataSource;
    public ChatContext $context;

    public function __construct($currentMessageId, $chatId, $task_list, $dashboardId, $userId)
    {
        $this->userId = $userId;
        $this->chat = AiChat::query()->find($chatId);
        $this->currentMessage = AiChatMessage::query()->find($currentMessageId);
        $this->dashboardId = $dashboardId;
        $this->statuses = TaskStatus::query()
            ->pluck('id', 'name')
            ->toArray();
        $this->tasks = Task::query()
            ->pluck('id', 'name')
            ->toArray();
        $this->messages = AiChatMessage::query()
            ->where('chat_id', $chatId)
            ->where('id', '!=', $currentMessageId)
            ->orderByDesc('id')
            ->limit(8)
            ->select('message', 'answer', 'offer_type', 'offer_summary')
            ->get();
        $this->task_list = $task_list;

        $this->dataSource = $this->chat?->resolveDataSource(['type']);

        $this->context = new ChatContext($chatId, $dashboardId);
    }

    public function define()
    {
        try {
            $this->current_task = AiChatTask::query()->create([
                'chat_id' => $this->currentMessage->chat_id,
                'message_id' => $this->currentMessage->id,
                'task_id' => $this->tasks['define_task'],
                'status_id' => $this->statuses['in_progress'],
            ]);
            $this->current_task->load(['status', 'task']);
            event(new MessageTasksChanged($this->currentMessage, $this->current_task, null));

            $this->resultDefine = $this->resolveTask();

            $this->currentMessage->tokens_used = $this->resultDefine['total_tokens'] ?? 0;

            $taskName = $this->resultDefine['content']['task_name'] ?? null;

            $routerMessage = in_array($taskName, ['generate_dashboard', 're_generate_dashboard', 'export_data'], true)
                ? trim((string) ($this->resultDefine['content']['message'] ?? ''))
                : '';

            if ($routerMessage !== '') {
                $this->currentMessage->answer = $routerMessage;
            }

            $this->currentMessage->status = 'generating';
            $this->currentMessage->save();

            $this->current_task->status_id = $this->statuses['completed'];
            $this->current_task->save();
            $this->current_task->load(['status', 'task']);

            event(new MessageTasksChanged($this->currentMessage, $this->current_task, null));

            $this->redirectToTask();

        } catch (Throwable $e) {

            Log::error($e->getMessage());
            Log::error($e->getTraceAsString());

            if ($this->current_task) {
                $this->current_task->status_id = $this->statuses['failed'];
                $this->current_task->save();
                $this->current_task->load(['status', 'task']);
            }

            $this->currentMessage->status = 'failed';
            $this->currentMessage->answer = $this->currentMessage->answer
                ?? 'Не удалось обработать запрос. Попробуйте ещё раз.';
            $this->currentMessage->save();

            $this->broadcastSafely($this->currentMessage, $this->current_task, null);

            throw $e;
        }
    }

    private function resolveTask(): array
    {
        $text = (string) $this->currentMessage->message;

        $previous = $this->messages->first();

        $context = IntentClassifier::contextFrom(
            $previous->offer_type ?? null,
            $previous->offer_summary ?? null,
            $previous->answer ?? null
        );

        $localRoutingEnabled = config('intents.mode', 'local') !== 'api';

        $classifier = new IntentClassifier();
        $prediction = $localRoutingEnabled ? $classifier->predict($text, $context) : null;

        if ($localRoutingEnabled && $classifier->isUnintelligible($text, $prediction)) {
            $this->clarification = $this->clarificationMessage();

            Log::info('RouterTask: сообщение не распознано, просим уточнить', [
                'message_id' => $this->currentMessage->id,
                'coverage' => round($prediction['coverage'] ?? 0, 3),
            ]);

            return [
                'content' => [
                    'task_name' => 'response_in_chat',
                    'task_title' => 'Уточнение запроса',
                    'task_instruction' => '',
                    'message' => '',
                ],
                'total_tokens' => 0,
                'source' => 'unintelligible',
            ];
        }

        $offeredType = trim((string) ($previous->offer_type ?? '')) ?: null;

        if ($prediction !== null && $classifier->isConfident($prediction, $offeredType)) {
            $resolved = $this->taskFromLabel($prediction['label'], $previous);

            Log::info('RouterTask: задача определена локально', [
                'message_id' => $this->currentMessage->id,
                'label' => $prediction['label'],
                'confidence' => round($prediction['confidence'], 3),
                'has_context' => $context !== '',
                'task_name' => $resolved['task_name'],
            ]);

            return ['content' => $resolved, 'total_tokens' => 0, 'source' => 'local'];
        }

        $response = (new DefineTaskAi($this->messages, $text, $this->task_list))
            ->defineTask($this->context->toArray());

        $taskName = $response['content']['task_name'] ?? null;

        Log::info('RouterTask: задача определена языковой моделью', [
            'message_id' => $this->currentMessage->id,
            'task_name' => $taskName,
            'local_label' => $prediction['label'] ?? null,
            'local_confidence' => isset($prediction) ? round($prediction['confidence'] ?? 0, 3) : null,
        ]);

        if (config('intents.learning.enabled', true) && $classifier->isLearnable($text, $prediction)) {
            IntentSample::remember(
                text: $text,
                label: IntentClassifier::labelForTask($taskName),
                prediction: $prediction,
                chatId: $this->currentMessage->chat_id,
                messageId: $this->currentMessage->id,
                context: $context
            );
        }

        return $response + ['source' => 'llm'];
    }

    private function instructionFor(string $label, $previous): string
    {
        $text = (string) $this->currentMessage->message;

        $summary = trim((string) ($previous->offer_summary ?? ''));
        $offered = trim((string) ($previous->offer_type ?? ''));

        if ($summary === '' || $offered !== $label) {
            return $text;
        }

        $words = count(preg_split('/\s+/u', trim($text)) ?: []);

        if ($words > 4) {
            return $text;
        }

        Log::info('RouterTask: задание взято из предложения агента', [
            'message_id' => $this->currentMessage->id,
            'offer_type' => $offered,
            'instruction' => $summary,
        ]);

        return $summary;
    }

    private function clarificationMessage(): string
    {
        return <<<'TEXT'
Не разобрал сообщение — похоже, оно набралось случайно.

Напишите, что нужно сделать:
- **спросить о данных** — «сколько заказов за март?»
- **изменить дашборд** — «добавь график по странам»
- **выгрузить файл** — «выгрузи клиентов в excel»
TEXT;
    }

    private function taskFromLabel(string $label, $previous = null): array
    {
        $text = $this->instructionFor($label, $previous);

        if ($label === IntentClassifier::EXPORT) {
            return [
                'task_name' => 'export_data',
                'task_title' => 'Выгрузка данных',
                'task_instruction' => $text,
                'message' => 'Готовлю файл с выгрузкой',
            ];
        }

        if ($label === IntentClassifier::DASHBOARD) {
            $hasDashboard = $this->context->hasDashboardWithWidgets();

            return [
                'task_name' => $hasDashboard ? 're_generate_dashboard' : 'generate_dashboard',
                'task_title' => $hasDashboard ? 'Обновление дашборда' : 'Создание дашборда',
                'task_instruction' => $text,
                'message' => $hasDashboard ? 'Запускаю обновление дашборда' : 'Запускаю создание дашборда',
            ];
        }

        return [
            'task_name' => 'response_in_chat',
            'task_title' => 'Ответ в чате',
            'task_instruction' => '',

            'message' => '',
        ];
    }

    public function redirectToTask()
    {
        $task = $this->resultDefine['content']['task_name'] ?? null;

        $dashboardId = $this->dashboardId ?? $this->context->dashboard?->id;

        if ($task === 're_generate_dashboard') {

            $targetHasWidgets = $dashboardId
                && $this->context->dashboard?->id === $dashboardId
                && $this->context->dashboardWidgets->isNotEmpty();

            if (!$dashboardId || !$targetHasWidgets) {

                Log::warning('RouterTask: re_generate_dashboard without existing widgets, falling back to generate', [
                    'message_id' => $this->currentMessage->id,
                    'dashboard_id' => $dashboardId,
                ]);
                $task = 'generate_dashboard';
            } else {
                dispatch(new DashboardReGeneratorJob(
                    $this->currentMessage->chat_id,
                    $dashboardId,
                    $this->currentMessage->id,
                    $this->resultDefine['content']['task_instruction'] ?? $this->currentMessage->message,

                    $this->messages
                ));

                return;
            }
        }

        if ($task === 'export_data') {
            if (!$this->dataSource) {
                $this->respondInChat('К этому чату не подключён источник данных, поэтому выгружать нечего. Подключите базу данных или загрузите файл — и я подготовлю выгрузку.');

                return;
            }

            dispatch(new ChatExportJob(
                $this->currentMessage->chat_id,
                $this->currentMessage->id,
                $this->resultDefine['content']['task_instruction'] ?? $this->currentMessage->message
            ));

            return;
        }

        if ($task === 'generate_dashboard') {
            if (!$this->dataSource) {

                $this->respondInChat('К этому чату не подключён источник данных, поэтому я не могу построить дашборд. Подключите базу данных или загрузите файл — и я сразу соберу аналитику.');

                return;
            }

            $title = trim((string) ($this->resultDefine['content']['task_title'] ?? ''));

            if ($title !== '') {
                $this->chat->title = $title;
                $this->chat->save();
            }

            $reuseDashboardId = ($dashboardId && $this->context->dashboard?->id === $dashboardId)
                ? $dashboardId
                : null;

            dispatch(new DashboardGeneratorJob(
                $this->currentMessage->id,
                $this->chat->id,
                $this->userId,
                $this->dataSource->id,
                $reuseDashboardId
            ));

            return;
        }

        if ($task !== 'response_in_chat') {
            Log::warning('RouterTask: unexpected task_name from DefineTaskAi, answering in chat', [
                'task_name' => $task,
                'message_id' => $this->currentMessage->id,
            ]);
        }

        $this->respondInChat($this->clarification);
    }

    private function ensureDataSourceGrouped(): void
    {
        if (!$this->dataSource || $this->context->hasGroups()) {
            return;
        }

        $task = null;

        try {
            $grouping = new DataSourceGrouping($this->dataSource->id);

            if ($grouping->load()) {
                return;
            }

            if (isset($this->tasks['data_source_grouping'])) {
                $task = AiChatTask::query()->create([
                    'chat_id' => $this->currentMessage->chat_id,
                    'message_id' => $this->currentMessage->id,
                    'task_id' => $this->tasks['data_source_grouping'],
                    'status_id' => $this->statuses['in_progress'],
                ]);
                $task->load(['status', 'task']);
                event(new MessageTasksChanged($this->currentMessage, $task, null));
            }

            $grouping->handle();
            $grouping->save();

            if ($task) {
                $task->status_id = $this->statuses['completed'];
                $task->save();
                $task->load('status');
                event(new MessageTasksChanged($this->currentMessage, $task, null));
            }

            $this->context = new ChatContext($this->currentMessage->chat_id, $this->dashboardId);

            Log::info('RouterTask: data source grouped for chat answer', [
                'data_source_id' => $this->dataSource->id,
                'groups' => $this->context->groups->count(),
            ]);
        } catch (Throwable $e) {

            Log::warning('RouterTask: grouping before chat answer failed', [
                'data_source_id' => $this->dataSource->id ?? null,
                'error' => $e->getMessage(),
            ]);

            if ($task) {
                $task->status_id = $this->statuses['failed'];
                $task->save();
                $task->load('status');
                $this->broadcastSafely($this->currentMessage, $task, null);
            }
        }
    }

    private function respondInChat(?string $forcedMessage = null): void
    {
        $task = null;

        if (isset($this->tasks['response_in_chat'])) {
            $task = AiChatTask::query()->create([
                'chat_id' => $this->currentMessage->chat_id,
                'message_id' => $this->currentMessage->id,
                'task_id' => $this->tasks['response_in_chat'],
                'status_id' => $this->statuses['in_progress'],
            ]);
            $task->load(['status', 'task']);
            event(new MessageTasksChanged($this->currentMessage, $task, null));
        }

        try {
            if ($forcedMessage !== null) {
                $answer = $forcedMessage;
                $this->currentMessage->offer_type = 'none';
                $this->currentMessage->offer_summary = '';
            } else {

                $this->ensureDataSourceGrouped();

                $agent = new ChatAgentAi(
                    $this->context,
                    $this->messages,
                    $this->currentMessage->message
                );

                $result = $agent->answer();

                $answer = $result['message'];

                $this->currentMessage->offer_type = $result['offer_type'] ?? 'none';
                $this->currentMessage->offer_summary = $result['offer_summary'] ?? '';

                $this->currentMessage->tokens_used =
                    (int) ($this->currentMessage->tokens_used ?? 0) + (int) $result['total_tokens'];
            }

            $this->currentMessage->answer = $answer;
            $this->currentMessage->status = 'answered';
            $this->currentMessage->save();

            IntentSample::confirm($this->currentMessage->id);

            if ($task) {
                $task->status_id = $this->statuses['completed'];
            }
        } catch (Throwable $e) {
            Log::error('RouterTask: chat agent failed: '.$e->getMessage());
            Log::error($e->getTraceAsString());

            $this->currentMessage->answer = $this->currentMessage->answer
                ?: 'Не удалось подготовить ответ. Попробуйте переформулировать вопрос.';
            $this->currentMessage->status = 'failed';
            $this->currentMessage->save();

            IntentSample::reject($this->currentMessage->id, 'агент не смог ответить');

            if ($task) {
                $task->status_id = $this->statuses['failed'];
            }
        }

        if ($task) {
            $task->save();
            $task->load(['status', 'task']);
        }

        $this->broadcastSafely($this->currentMessage, $task, null);
    }

    private function broadcastSafely($message, $task, $dashboardId): void
    {
        try {
            event(new MessageTasksChanged($message, $task, $dashboardId));
        } catch (Throwable $e) {
            Log::warning('RouterTask: broadcast failed, state is saved in DB', [
                'message_id' => $message->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
