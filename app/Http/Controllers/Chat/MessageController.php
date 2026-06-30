<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'chat_id' => 'required|integer',
        ]);

        $user = auth()->user();

        $messages = AiChatMessage::query()
            ->where('chat_id', $request->chat_id)
            ->whereHas('chat', function ($query) use ($user) {
                $query->where('company_id', $user->company->id);
            })
            ->orderBy('created_at')
            ->get();

        return response()->json($messages);
    }

    public function store(Request $request)
    {
        $request->validate([
            'chat_id' => 'required|integer',
            'message' => 'required|string|min:1',
        ]);

        $user = auth()->user();

        $chat = AiChat::query()
            ->where('id', $request->chat_id)
            ->where('company_id', $user->company->id)
            ->firstOrFail();

        $message = AiChatMessage::create([
            'chat_id' => $chat->id,
            'message' => $request->message,
        ]);

        return response()->json($message, 201);
    }

    public function show($id)
    {
        $user = auth()->user();

        $message = AiChatMessage::query()
            ->where('id', $id)
            ->whereHas('chat', function ($query) use ($user) {
                $query->where('company_id', $user->company->id);
            })
            ->firstOrFail();

        return response()->json($message);
    }
}
