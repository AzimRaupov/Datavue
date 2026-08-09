<?php

namespace App\Jobs;

use App\Events\DashboardWidgetChanged;
use App\Helpers\Widget\ReviewWidgetsDashboard;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\AiChatTask;
use App\Models\Dashboard;
use App\Models\Task;
use App\Models\TaskStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

class ReviewWidgetsDashboardJob implements ShouldQueue
{
    use Queueable,SerializesModels;
    public $timeout = 600;

    public $tasks_statuses;
    public $tasks;
    public $dashboard;
    public $dataSourceId;
    public $message;
    public function __construct($dashboardId,$dataSourceId,$messageId)
    {
        $this->dashboard = Dashboard::find($dashboardId);
        $this->dataSourceId = $dataSourceId;
        $this->message = AiChatMessage::query()->find($messageId);
        $this->tasks_statuses = TaskStatus::query()
            ->pluck('id', 'name')
            ->toArray();
        $this->tasks = Task::query()
            ->pluck('id', 'name')
            ->toArray();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        \App\Helpers\Ai\AiUsageContext::set(
            \App\Models\Dashboard::query()->whereKey($this->dashboardId)->value('company_id'),
            \App\Models\Dashboard::query()->whereKey($this->dashboardId)->value('chat_id'),
            $this->messageId,
            'review_widgets'
        );

        try {
        $review=new ReviewWidgetsDashboard($this->dashboard->id,$this->dataSourceId);
        $resultReview = $review->review($review->dataSource, $review->dashboard_widgets);

        if($resultReview['isError']){
            $task = AiChatTask::query()->create([
                'chat_id' => $this->message->chat_id,
                'message_id' => $this->message->id,
                'task_id' => $this->tasks['review_and_correction_widgets'],
                'status_id' => $this->tasks_statuses['in_progress'],
            ]);
            $task->load(['status', 'task']);
            $this->dashboard->status="reviewing";
            $this->dashboard->save();
            event(new DashboardWidgetChanged($this->dashboard));

            event(new \App\Events\MessageTasksChanged($this->message, $task,$this->dashboard->id));

            $review->startReGenerate($resultReview['result']);
            $task->status_id = $this->tasks_statuses['completed'];
            $task->save();
            $task->load('status');



            event(new \App\Events\MessageTasksChanged($this->message, $task));
            $this->dashboard->status = "completed";
            $this->dashboard->save();
            event(new DashboardWidgetChanged($this->dashboard));
        }

        } finally {
            // Воркер долгоживущий — контекст обязан сбрасываться.
            \App\Helpers\Ai\AiUsageContext::clear();
        }
    }
}
