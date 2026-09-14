<?php

namespace App\Jobs;

use App\Events\MessageTasksChanged;
use App\Helpers\Ai\AiUsageContext;
use App\Helpers\Export\ChatExportGenerator;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\AiChatTask;
use App\Models\IntentSample;
use App\Models\Task;
use App\Models\TaskStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChatExportJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 600;

    public function __construct(
        public int $chatId,
        public int $messageId,
        public string $instruction
    ) {
    }

    public function handle(): void
    {
        $companyId = AiChat::query()->whereKey($this->chatId)->value('company_id');

        AiUsageContext::set($companyId, $this->chatId, $this->messageId, 'export_data');

        $message = AiChatMessage::query()->find($this->messageId);

        if (!$message) {
            AiUsageContext::clear();

            return;
        }

        $statuses = TaskStatus::query()->pluck('id', 'name')->toArray();
        $taskId = Task::query()->where('name', 'export_data')->value('id');

        $task = $taskId
            ? AiChatTask::query()->create([
                'chat_id' => $this->chatId,
                'message_id' => $this->messageId,
                'task_id' => $taskId,
                'status_id' => $statuses['in_progress'],
            ])
            : null;

        $task?->load(['status', 'task']);

        if ($task) {
            $this->broadcastSafely($message, $task);
        }

        try {
            $generator = new ChatExportGenerator(
                $this->chatId,
                $this->messageId,
                $this->instruction
            );

            $result = $generator->handle();

            $message->answer = $result['answer'];
            $message->status = 'answered';
            $message->tokens_used = (int) ($message->tokens_used ?? 0) + (int) $result['total_tokens'];
            $message->save();

            if ($task) {
                $task->status_id = $statuses['completed'];
                $task->save();
                $task->load(['status', 'task']);
            }

            IntentSample::confirm($this->messageId);

            Log::info('ChatExportJob: export ready', [
                'message_id' => $this->messageId,
                'export_id' => $result['export']->id,
                'format' => $result['export']->format,
                'rows' => $result['export']->rows_count,
            ]);
        } catch (Throwable $e) {
            IntentSample::reject($this->messageId, 'выгрузка не удалась');

            Log::error('ChatExportJob: export failed: '.$e->getMessage());
            Log::error($e->getTraceAsString());

            $message->answer = 'Не удалось сформировать файл: '.$e->getMessage()
                ."\n\nПопробуйте сформулировать выгрузку конкретнее — например, "
                .'«выгрузи в csv топ-10 клиентов по сумме заказов за 2024 год».';
            $message->status = 'failed';
            $message->save();

            if ($task) {
                $task->status_id = $statuses['failed'];
                $task->save();
                $task->load(['status', 'task']);
            }
        } finally {
            $this->broadcastSafely($message, $task);

            AiUsageContext::clear();
        }
    }

    private function broadcastSafely($message, $task): void
    {
        try {
            event(new MessageTasksChanged($message, $task, null));
        } catch (Throwable $e) {
            Log::warning('ChatExportJob: broadcast failed, state is saved in DB', [
                'message_id' => $message->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
