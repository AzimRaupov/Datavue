<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageTasksChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    private const MAX_PAYLOAD_BYTES = 9000;

    public $message;
    public $task;
    public $dashboardId = null;

    public function broadcastAs(): string
    {
        return 'MessageTasksChanged';
    }

    public function __construct($message, $task, $dashboardId = null)
    {
        $this->message = $message;
        $this->task = $task;
        $this->dashboardId = $dashboardId;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tasks.' . $this->message->chat_id),
        ];
    }

    public function broadcastWith(): array
    {
        $payload = [
            'message' => [
                'id' => $this->message->id,
                'chat_id' => $this->message->chat_id,
                'status' => $this->message->status,
                'answer' => $this->message->answer,
                'tokens_used' => $this->message->tokens_used,
                'updated_at' => $this->message->updated_at,
            ],
            'task' => $this->task,
            'dashboard_id' => $this->dashboardId,
            'answer_truncated' => false,
        ];

        if ($this->fitsInLimit($payload)) {
            return $payload;
        }

        unset($payload['message']['answer']);
        $payload['answer_truncated'] = true;

        return $payload;
    }

    private function fitsInLimit(array $payload): bool
    {
        $encoded = json_encode($payload);

        if ($encoded === false) {
            return false;
        }

        return strlen(json_encode($encoded)) <= self::MAX_PAYLOAD_BYTES;
    }
}
