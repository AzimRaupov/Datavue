<?php

namespace App\Http\Controllers\Chat;

use App\Events\MessageTasksChanged;
use App\Helpers\Task\RouterTask;
use App\Http\Controllers\Controller;
use App\Jobs\RouterTaskJob;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\Dashboard;
use App\Models\Task;
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
            ->with(['tasks.status','tasks.task'])
            ->orderBy('created_at')
            ->get();

        return response()->json($messages);
    }

    public function store(Request $request)
    {
        $request->validate([
            'chat_id' => 'required|integer|exists:ai_chats,id',
            'message' => 'required|string|min:1',
            'dashboard_id' =>'nullable|integer|exists:dashboards,id',
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
        $dashboard_count = Dashboard::query()->where('chat_id',$chat->id)->count();
        if($dashboard_count>0) {
            $task_list = Task::query()->where('name', 're_generate_dashboard')
                ->orWhere('name', 'response_in_chat')
                ->select('name', 'description')
                ->get();
        }
        else{
            $task_list = Task::query()->where('name', 'generate_dashboard')
                ->orWhere('name', 'response_in_chat')
                ->select('name', 'description')
                ->get();
        }


        dispatch(new RouterTaskJob($message->id,$chat->id,$task_list->toArray(),$request->dashboard_id,$user->id));
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
