<?php

namespace App\Http\Controllers\Chat;

use App\Events\MessageTasksChanged;
use App\Helpers\Ai\AiUsage;
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

        // Лимит проверяем ДО создания сообщения: каждое сообщение запускает
        // цепочку запросов к модели, то есть прямой расход. Иначе компания
        // с исчерпанным бюджетом продолжала бы тратить, а пользователь видел
        // бы молча падающие задачи.
        if (AiUsage::limitReached($user->company)) {
            $summary = AiUsage::summary($user->company);

            return response()->json([
                'message' => sprintf(
                    'Исчерпан месячный лимит на ИИ: израсходовано %s из %s токенов. Лимит обновится в начале месяца.',
                    number_format($summary['used'], 0, '.', ' '),
                    number_format($summary['limit'], 0, '.', ' ')
                ),
                'usage' => $summary,
            ], 429);
        }

        $message = AiChatMessage::create([
            'chat_id' => $chat->id,
            'message' => $request->message,
        ]);
        // "response_in_chat" должен быть доступен ВСЕГДА — иначе ИИ вынужден
        // выбирать между генерацией/перегенерацией дашборда даже для обычного
        // приветствия или вопроса, т.к. этой опции просто нет в списке.
        // "generate_dashboard" тоже доступен всегда — пользователь может
        // попросить новый дашборд по другой теме, даже если для этого чата уже
        // есть один. "re_generate_dashboard" осмысленен, только если дашборд
        // (хотя бы один) уже существует — иначе регенерировать нечего.
        $taskNames = ['response_in_chat', 'generate_dashboard'];

        $dashboard_count = Dashboard::query()->where('chat_id', $chat->id)->count();
        if ($dashboard_count > 0) {
            $taskNames[] = 're_generate_dashboard';
        }

        $task_list = Task::query()
            ->whereIn('name', $taskNames)
            ->select('name', 'description')
            ->get();


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
