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


        $router= new RouterTask($this->currentMessageId,$this->chatId,$this->task_list,$this->dashboardId,$this->userId);
        $router->define();
    }
}
