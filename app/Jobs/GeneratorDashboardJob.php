<?php

namespace App\Jobs;

use App\Helpers\Dashboard\Builder\DashboardBuilder;
use App\Helpers\Dashboard\DashboardGenerator;
use App\Helpers\Dashboard\DataHandlers\SqlDataHandler;
use App\Helpers\Dashboard\DataHandlers\TableDataHandler;
use App\Models\AiChatTask;
use App\Models\UploadedFile;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GeneratorDashboardJob implements ShouldQueue
{
    use Queueable;
    public $chat_id;
    public $message_id;
    public $upload_id;
    public $timeout = 600;
    public $upload;
    public $user_id;
    /**
     * Create a new job instance.
     */
    public function __construct($message_id, $chat_id, $user_id)
    {
        $this->message_id = $message_id;
        $this->chat_id = $chat_id;
        $this->user_id = $user_id;

        $chat_task = AiChatTask::query()->create([
            'chat_id' => $chat_id,
            'task_id' => 1,
            'status_id'=> 7
        ]);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {



        $dashboard_generate=new DashboardGenerator($this->chat_id, $this->message_id);

        $dashboard_generate->generateWidgets();


        $dashboard_generate->generateContentToWidgets();
        $dashboard=$dashboard_generate->getDashboard();




    }
}
