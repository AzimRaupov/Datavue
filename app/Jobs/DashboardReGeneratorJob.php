<?php

namespace App\Jobs;

use App\Helpers\Dashboard\DashboardReGenerator;
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
    }
}
