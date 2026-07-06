<?php

namespace App\Helpers\Task;

use App\Events\MessageTasksChanged;
use App\Models\AiChatMessage;
use App\Models\AiChatTask;
use App\Models\Task;
use App\Models\TaskStatus;

class RouterTask
{
    public $messages;
    public $currentMessage;

    public $current_task;
    public $statuses;
    public $tasks;

    public function __construct($currentMessageId, $chatId){

        $this->currentMessage = AiChatMessage::query()->find($currentMessageId);

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
            ->select('message','answer')
            ->get();

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
            $this->tasks = Task::query()->where('name','generate_dashboard')
                ->orWhere('name','response_in_chat')
                ->select('name','description')
                ->get();

            $define_task = new \App\Helpers\Task\DefineTask($this->messages,$this->currentMessage->message);

            $result = $define_task->defineTask();

            $this->currentMessage->tokens_used = $result['total_tokens'];
            $this->currentMessage->answer = $result['content']['message'];
            $this->currentMessage->status = 'answered';
            $this->currentMessage->save();

            $this->current_task->status_id=$this->statuses['completed'];
            $this->current_task->save();

            event(new MessageTasksChanged($this->currentMessage));

        } catch (\Throwable $e) {

            \Log::error($e->getMessage());
            \Log::error($e->getTraceAsString());
            $this->current_task->status_id = $this->statuses['failed'];

            throw $e;
        }

    }

}
