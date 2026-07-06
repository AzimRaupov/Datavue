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
    public $dashboard_id;
    /**
     * Create a new job instance.
     */
    public function __construct($message_id, $chat_id, $user_id,$dashboard_id)
    {
        $this->message_id = $message_id;
        $this->chat_id = $chat_id;
        $this->user_id = $user_id;
        $this->dashboard_id = $dashboard_id;

    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $dashboard_generate = new DashboardGenerator(
                $this->chat_id,
                $this->message_id,
                $this->dashboard_id
            );

            $dashboard_generate->generateWidgets();
            $dashboard_generate->generateContentToWidgets();

        } catch (\Throwable $e) {
            \Log::error($e->getMessage());
            \Log::error($e->getTraceAsString());

            throw $e;
        }
    }
}
