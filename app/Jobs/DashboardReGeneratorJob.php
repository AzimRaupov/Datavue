<?php

namespace App\Jobs;

use App\Events\DashboardWidgetChanged;
use App\Events\MessageTasksChanged;
use App\Helpers\Dashboard\DashboardReGenerator;
use App\Helpers\Widget\ReviewWidgetsDashboard;
use App\Models\AiChatTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DashboardReGeneratorJob implements ShouldQueue
{
    use Queueable;

    public $dashboardId;
    public $chatId;
    public $text;
    public $messageId;
    public $timeout = 600;

    /**
     * Create a new job instance.
     */
    public function __construct($chatId, $dashboardId, $messageId ,$text)
    {
        $this->chatId = $chatId;
        $this->dashboardId = $dashboardId;
        $this->messageId = $messageId;
        $this->text = $text;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $d=new DashboardReGenerator($this->dashboardId,$this->chatId,$this->messageId);
        $d->determineChanges($this->text);
        $d->applyChanges();

        $review = new ReviewWidgetsDashboard($d->newDashboard->id, $d->dataSource->id);

        $task = AiChatTask::query()->create([
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId,
            'task_id' => $d->tasks['review_and_correction_widgets'],
            'status_id' => $d->tasks_statuses['in_progress'],
        ]);
        $task->load(['status', 'task']);

        $d->newDashboard->status = 'reviewing';
        $d->newDashboard->save();

        event(new MessageTasksChanged($d->message, $task,  $d->newDashboard->id));
        event(new DashboardWidgetChanged($d->newDashboard));

        $result = $review->handle();


        $task->status_id = $d->tasks_statuses['completed'];
        $task->save();
        $task->load('status');
        $d->message->status="answered";

        event(new MessageTasksChanged($d->message, $task, $d->newDashboard->id));

        $d->dashboard->status = 'completed';
        $d->dashboard->save();
        event(new DashboardWidgetChanged($d->newDashboard));

    }
}
