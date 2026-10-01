<?php

namespace App\Http\Controllers\Chat;

use App\Helpers\Ai\AiUsage;
use App\Helpers\Chat\DashboardSuggestionGenerator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StoreRequest;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\AiChatTask;
use App\Models\DashboardSuggestion;
use App\Models\DataSource;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = AiChat::query()
            ->where('company_id', $user->company_id)
            ->with([
                'dataSource:id,name,type_id,connection_type,origin_format',
                'dataSource.type:id,name',
                'dashboards' => fn ($query) => $query
                    ->select('id', 'name', 'chat_id', 'status')
                    ->latest()
                    ->limit(3),
            ])
            ->withCount('dashboards')
            ->latest('id');

        if ($request->filled('data_source_id')) {
            $query->where('data_source_id', $request->integer('data_source_id'));
        }

        return response()->json($query->get());
    }

    public function show(Request $request, $id)
    {
        $chat = AiChat::query()
            ->where('id', $id)
            ->where('company_id', $request->user()->company_id)
            ->with([
                'dataSource:id,name,type_id,connection_type,origin_format,database',
                'dataSource.type:id,name',
                'dashboards' => function ($query) {
                    $query->select('id', 'name', 'chat_id');
                },
            ])
            ->firstOrFail();

        $suggestions = DashboardSuggestion::query()
            ->where('data_source_id', $chat->data_source_id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json(
            $chat->toArray() + ['suggestions' => $suggestions]
        );
    }

    public function store(StoreRequest $request)
    {
        $user = $request->user();

        $dataSource = DataSource::query()
            ->ofCompany($user->company_id)
            ->find($request->input('data_source_id'));

        if (!$dataSource) {
            return response()->json([
                'success' => false,
                'message' => 'Источник данных не найден.',
            ], 404);
        }

        $title = $request->input('title') ?: 'Новый чат — ' . $dataSource->name;

        $workspace = Workspace::query()->create([
            'company_id' => $user->company_id,
            'created_by' => $user->id,
            'data_source_id' => $dataSource->id,
            'name' => $title,
        ]);

        $chat = AiChat::create([
            'user_id'        => $user->id,
            'company_id'     => $user->company_id,
            'workspace_id'   => $workspace->id,
            'data_source_id' => $dataSource->id,
            'title'          => $title,
        ]);

        $suggestions = AiUsage::limitReached($user->company)
            ? collect()
            : (new DashboardSuggestionGenerator($dataSource))->handle();

        return response()->json([
            'success' => true,
            'message' => 'Чат создан.',
            'chat' => $chat->load('dataSource:id,name,type_id,origin_format', 'dataSource.type:id,name,label'),
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'suggestions' => $suggestions,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $chat = $this->findForCompany($request, $id);

        $data = $request->validate([
            'title' => 'required|string|max:255',
        ]);

        $chat->title = $data['title'];
        $chat->save();

        return response()->json($chat);
    }

    public function destroy(Request $request, $id)
    {
        $chat = $this->findForCompany($request, $id);

        DB::transaction(function () use ($chat) {
            foreach ($chat->dashboards as $dashboard) {
                $dashboard->widgets()->delete();
                $dashboard->delete();
            }

            AiChatTask::query()->where('chat_id', $chat->id)->delete();
            AiChatMessage::query()->where('chat_id', $chat->id)->delete();

            $chat->delete();
        });

        return response()->json(['message' => 'Чат удалён.']);
    }

    private function findForCompany(Request $request, $id): AiChat
    {
        return AiChat::query()
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($id);
    }
}
