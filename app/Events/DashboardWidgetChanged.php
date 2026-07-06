<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DashboardWidgetChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $dashboard;

    // Передаем саму модель сообщения, у которого изменились таски
    public function __construct($dashboard)
    {
        $this->dashboard = $dashboard;
    }

    public function broadcastAs(): string
    {
        return 'DashboardWidgetChanged';
    }
    // Канал привязан к ID сообщения/чата. Публичный, как ты просил.
    public function broadcastOn(): array
    {
        return [
            new Channel('dashboard.' . $this->dashboard->id),
        ];
    }

    // Передаем ID сообщения и его свежий список задач
    public function broadcastWith(): array
    {
        return [
            'dashboard_id' => $this->dashboard->id,
        ];
    }
}
