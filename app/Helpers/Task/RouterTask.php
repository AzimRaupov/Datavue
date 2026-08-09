<?php

namespace App\Helpers\Task;

use App\Events\MessageTasksChanged;
use App\Helpers\Ai\ChatAgentAi;
use App\Helpers\Ai\DefineTaskAi;
use App\Helpers\Chat\ChatContext;
use App\Helpers\DataSource\DataSourceGrouping;
use App\Jobs\DashboardGeneratorJob;
use App\Jobs\DashboardReGeneratorJob;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\AiChatTask;
use App\Models\DataSource;
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
            ->select('message', 'answer')
            ->get();
        $this->task_list = $task_list;
        // Источник теперь принадлежит компании и привязан к чату полем
        // ai_chats.data_source_id, а не наоборот.
        $this->dataSource = $this->chat?->resolveDataSource(['type']);

        // Полный контекст (дашборд, виджеты, группы таблиц, каталог виджетов).
        // ChatContext сам находит актуальный дашборд, если фронт не передал id —
        // раньше в этом случае модель вообще не видела виджетов.
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

            $define_task = new DefineTaskAi($this->messages, $this->currentMessage->message, $this->task_list);

            $this->resultDefine = $define_task->defineTask($this->context->toArray());

            $this->currentMessage->tokens_used = $this->resultDefine['total_tokens'] ?? 0;

            // Для "response_in_chat" роутер намеренно возвращает пустой message —
            // содержательный ответ готовит ChatAgentAi, у которого есть доступ
            // к данным. Пустым значением затирать ничего не нужно.
            $routerMessage = trim((string) ($this->resultDefine['content']['message'] ?? ''));

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

            // Broadcast в обработчике ошибок не должен подменять исходную
            // причину сбоя своей собственной — иначе настоящая ошибка теряется.
            $this->broadcastSafely($this->currentMessage, $this->current_task, null);

            throw $e;
        }
    }

    public function redirectToTask()
    {
        $task = $this->resultDefine['content']['task_name'] ?? null;

        // Фронт мог не передать dashboard_id — берём актуальный дашборд чата.
        $dashboardId = $this->dashboardId ?? $this->context->dashboard?->id;

        if ($task === 're_generate_dashboard') {
            if (!$dashboardId) {
                // Регенерировать нечего — значит на самом деле нужен новый дашборд.
                Log::warning('RouterTask: re_generate_dashboard without dashboard, falling back to generate', [
                    'message_id' => $this->currentMessage->id,
                ]);
                $task = 'generate_dashboard';
            } else {
                dispatch(new DashboardReGeneratorJob(
                    $this->currentMessage->chat_id,
                    $dashboardId,
                    $this->currentMessage->id,
                    $this->resultDefine['content']['task_instruction'] ?? $this->currentMessage->message
                ));

                return;
            }
        }

        if ($task === 'generate_dashboard') {
            if (!$this->dataSource) {
                // Без источника данных строить нечего — честно говорим об этом
                // в чате вместо падения джоба с фатальной ошибкой.
                $this->respondInChat('К этому чату не подключён источник данных, поэтому я не могу построить дашборд. Подключите базу данных или загрузите файл — и я сразу соберу аналитику.');

                return;
            }

            $title = trim((string) ($this->resultDefine['content']['task_title'] ?? ''));

            if ($title !== '') {
                $this->chat->title = $title;
                $this->chat->save();
            }

            dispatch(new DashboardGeneratorJob(
                $this->currentMessage->id,
                $this->chat->id,
                $this->userId,
                $this->dataSource->id
            ));

            return;
        }

        if ($task !== 'response_in_chat') {
            Log::warning('RouterTask: unexpected task_name from DefineTaskAi, answering in chat', [
                'task_name' => $task,
                'message_id' => $this->currentMessage->id,
            ]);
        }

        $this->respondInChat();
    }

    /**
     * Гарантирует, что таблицы источника разложены по смысловым группам.
     *
     * Группировка — разовая операция на источник, но раньше она запускалась
     * исключительно при генерации дашборда. Пользователь, который в новом чате
     * сразу задаёт вопрос по данным, получал агента без групп: список таблиц
     * в контекст не попадал, и сузить круг было нечем.
     *
     * Контекст после построения пересобирается — иначе агент продолжил бы
     * работать со снимком, снятым до появления групп.
     */
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
            // Без групп агент всё ещё может работать по именам таблиц напрямую,
            // поэтому провал группировки не должен ронять ответ пользователю.
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

    /**
     * Готовит содержательный ответ пользователю через ChatAgentAi.
     *
     * $forcedMessage используется, когда ответ известен заранее и обращаться
     * к модели незачем (например, не подключён источник данных).
     */
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
            } else {
                // В новом чате группировка ещё не выполнялась: её запускает только
                // построение дашборда. Агент при этом оставался без единой таблицы
                // в контексте и отвечал вслепую. Строим группы здесь — так же,
                // как это делает DashboardGeneratorJob, и с тем же шагом в интерфейсе.
                $this->ensureDataSourceGrouped();

                $agent = new ChatAgentAi(
                    $this->context,
                    $this->messages,
                    $this->currentMessage->message
                );

                $result = $agent->answer();

                $answer = $result['message'];
                $this->currentMessage->tokens_used =
                    (int) ($this->currentMessage->tokens_used ?? 0) + (int) $result['total_tokens'];
            }

            $this->currentMessage->answer = $answer;
            $this->currentMessage->status = 'answered';
            $this->currentMessage->save();

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

            if ($task) {
                $task->status_id = $this->statuses['failed'];
            }
        }

        if ($task) {
            $task->save();
            $task->load(['status', 'task']);
        }

        // Ответ уже сохранён в БД. Если сокет по какой-то причине не принял
        // событие, это не повод помечать сообщение неудачным и терять ответ —
        // клиент получит его при следующей загрузке сообщений.
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
