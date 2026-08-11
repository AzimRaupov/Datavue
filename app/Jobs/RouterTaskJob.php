<?php

namespace App\Jobs;

use App\Helpers\Task\RouterTask;
use App\Models\DashboardWidget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RouterTaskJob implements ShouldQueue
{
    use Queueable;

    public $currentMessageId;
    public $timeout = 600;

    public $chatId;
    public $task_list;
    public $dashboardId;
    public $userId;

    /**
     * Create a new job instance.
     */
    public function __construct($currentMessageId,$chatId,$task_list,$dashboardId,$userId)
    {
        $this->currentMessageId = $currentMessageId;
        $this->chatId = $chatId;
        $this->task_list = $task_list;
        $this->dashboardId = $dashboardId;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {


        $companyId = \App\Models\AiChat::query()->whereKey($this->chatId)->value('company_id');

        \App\Helpers\Ai\AiUsageContext::run(
            $companyId,
            function () {
                $router = new RouterTask($this->currentMessageId, $this->chatId, $this->task_list, $this->dashboardId, $this->userId);
                $router->define();
            },
            chatId: $this->chatId,
            messageId: $this->currentMessageId,
            operation: 'route_task'
        );
    }
}
