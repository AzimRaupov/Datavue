<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DashboardWidgetChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $dashboard;

    public function __construct($dashboard)
    {
        $this->dashboard = $dashboard;
    }

    public function broadcastAs(): string
    {
        return 'DashboardWidgetChanged';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('dashboard.' . $this->dashboard->id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'dashboard_id' => $this->dashboard->id,
        ];
    }
}
