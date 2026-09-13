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

    /**
     * Потолок размера полезной нагрузки. Reverb режет сообщения больше
     * max_message_size (по умолчанию 10 000 байт), причём в Pusher-протоколе
     * данные едут строкой внутри JSON — то есть кодируются ДВАЖДЫ, и каждый
     * кириллический символ превращается в "\\u0430" (12 байт вместо 2).
     * Развёрнутый ответ агента легко перебивает лимит, и тогда падает весь
     * broadcast, а сообщение помечается как неудачное, хотя ответ уже готов.
     */
    private const MAX_PAYLOAD_BYTES = 9000;

    public $message;
    public $task;
    public $dashboardId = null;

    public function broadcastAs(): string
    {
        return 'MessageTasksChanged';
    }

    // Передаем саму модель сообщения, у которого изменились таски
    public function __construct($message, $task, $dashboardId = null)
    {
        $this->message = $message;
        $this->task = $task;
        $this->dashboardId = $dashboardId;
    }

    /**
     * Канал приватный: в событии едет текст ответа агента, а имя канала —
     * это всего лишь номер чата. На публичном канале его мог бы слушать кто
     * угодно, зная только ключ приложения из собранного фронтенда.
     * Кого пускать, решает routes/channels.php.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tasks.' . $this->message->chat_id),
        ];
    }

    /**
     * Шлём только те поля, которые реально нужны фронту, а не всю модель.
     * Текст самого запроса пользователя и tool_results клиенту уже известны
     * или не нужны — незачем гонять их через сокет.
     */
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

        // Ответ не помещается в лимит сокета — не шлём его вовсе (иначе упадёт
        // весь broadcast). Ключ 'answer' убираем целиком, чтобы фронт при
        // слиянии не затёр уже показанный текст, и поднимаем флаг: клиент
        // дозагрузит полный ответ обычным HTTP-запросом.
        unset($payload['message']['answer']);
        $payload['answer_truncated'] = true;

        return $payload;
    }

    /**
     * Оценивает итоговый размер с учётом двойного кодирования Pusher-протокола.
     */
    private function fitsInLimit(array $payload): bool
    {
        $encoded = json_encode($payload);

        if ($encoded === false) {
            return false;
        }

        return strlen(json_encode($encoded)) <= self::MAX_PAYLOAD_BYTES;
    }
}
