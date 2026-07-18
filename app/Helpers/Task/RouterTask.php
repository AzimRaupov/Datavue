<?php

namespace App\Helpers\Task;

use App\Events\MessageTasksChanged;
use App\Helpers\Ai\DefineTaskAi;
use App\Jobs\DashboardGeneratorJob;
use App\Jobs\DashboardReGeneratorJob;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\AiChatTask;
use App\Models\DashboardWidget;
use App\Models\Task;
use App\Models\TaskStatus;

class RouterTask
{
    public $messages;
    public $chat;
    public $currentMessage;

    public $current_task;
    public $statuses;
    public $tasks;
    public $task_list;
    public $widgets;
    public $dashboardId;
    public $resultDefine;
    public $userId;
    public function __construct($currentMessageId, $chatId,$task_list,$dashboardId,$userId)
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
        $this->widgets = DashboardWidget::query()->where('dashboard_id', $this->dashboardId)
            ->select('title')->get();
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
            event(new MessageTasksChanged($this->currentMessage, $this->current_task,null));

            $define_task = new DefineTaskAi($this->messages, $this->currentMessage->message,$this->task_list);
            $this->resultDefine = $define_task->defineTask($this->widgets->toArray());
            $this->currentMessage->tokens_used = $this->resultDefine['total_tokens'];
            $this->currentMessage->answer = $this->resultDefine['content']['message'];
            $this->currentMessage->status = 'answered';
            $this->currentMessage->save();

            $this->current_task->status_id = $this->statuses['completed'];
            $this->current_task->save();
            $this->current_task->load(['status', 'task']);

            event(new MessageTasksChanged($this->currentMessage, $this->current_task,null));

            $this->redirectToTask();


        } catch (\Throwable $e) {

            \Log::error($e->getMessage());
            \Log::error($e->getTraceAsString());

            if ($this->current_task) {
                $this->current_task->status_id = $this->statuses['failed'];
                $this->current_task->save();
                $this->current_task->load(['status', 'task']);
            }

            $this->currentMessage->status = 'failed';
            $this->currentMessage->answer = $this->currentMessage->answer
                ?? 'Не удалось обработать запрос. Попробуйте ещё раз.';
            $this->currentMessage->save();

            event(new MessageTasksChanged($this->currentMessage, $this->current_task));

            throw $e;
        }
    }
    public function redirectToTask()
    {
        $task = $this->resultDefine['content']['task_name'];
        if($task=="re_generate_dashboard"){
            dispatch(new DashboardReGeneratorJob($this->currentMessage->chat_id,$this->dashboardId,$this->currentMessage->id,$this->resultDefine['content']['task_instruction']));
        }
        else if($task=="generate_dashboard"){
            $this->chat->title = $this->resultDefine['content']['task_title'];
            $this->chat->save();
            dispatch(new DashboardGeneratorJob($this->currentMessage->id,$this->chat->id,$this->userId));

        }
    }
}
