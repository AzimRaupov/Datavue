<?php

namespace App\Helpers\Task;

use App\Models\AiChatMessage;

class RouterTask
{
    public $messages;
    public $currentMessage;


    public function __construct($currentMessageId, $chatId){

        $this->currentMessage = AiChatMessage::query()->find($currentMessageId);

        $this->messages = AiChatMessage::query()
            ->where('chat_id', $chatId)
            ->where('id', '!=', $currentMessageId)
            ->orderByDesc('id')
            ->limit(8)
            ->select('message','answer')
            ->get();


    }

}
