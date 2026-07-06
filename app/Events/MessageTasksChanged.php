<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageTasksChanged implements ShouldBroadcastNow // <-- ОБЯЗАТЕЛЬНО
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;

    public function broadcastAs(): string
    {
        return 'MessageTasksChanged';
    }
    // Передаем саму модель сообщения, у которого изменились таски
    public function __construct($message)
    {
        $this->message = $message;
    }

    // Канал привязан к ID сообщения/чата. Публичный, как ты просил.
    public function broadcastOn(): array
    {
        return [
            new Channel('tasks.' . $this->message->chat_id),
        ];
    }

    // Передаем ID сообщения и его свежий список задач
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->id,
            'tasks'      => $this->message->tasks()->with('task', 'status')->get()->toArray(),
        ];
    }
}
