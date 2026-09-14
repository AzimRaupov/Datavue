<?php

namespace App\Events;

use App\Models\DataSource;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DataSourceGroupingProgress implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public DataSource $dataSource,
        public string $stage,
        public string $label,
        public int $step,
        public int $total,
        public string $status = 'in_progress',
        public ?string $message = null
    ) {
    }

    public function broadcastAs(): string
    {
        return 'DataSourceGroupingProgress';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('data_source.' . $this->dataSource->id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'data_source_id' => $this->dataSource->id,
            'stage' => $this->stage,
            'label' => $this->label,
            'step' => $this->step,
            'total' => $this->total,
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}
