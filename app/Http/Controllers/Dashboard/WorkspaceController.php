<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\AiChatTask;
use App\Models\Dashboard;
use App\Models\DashboardSuggestion;
use App\Models\DataSource;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class WorkspaceController extends Controller
{

    public function index(Request $request)
    {
        $workspaces = Workspace::query()
            ->ofCompany($request->user()->company_id)
            ->with('dataSource:id,name,type_id', 'dataSource.type:id,name')
            ->withCount('dashboards')
            ->orderByDesc('id')
            ->get();

        return response()->json(
            $workspaces->map(fn (Workspace $workspace) => $this->card($workspace))->values()
        );
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',

            'data_source_id' => [
                'required',
                Rule::exists('data_sources', 'id')->where('company_id', $user->company_id),
            ],
        ]);

        $workspace = Workspace::query()->create([
            'company_id' => $user->company_id,
            'created_by' => $user->id,
            'data_source_id' => $data['data_source_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        return response()->json($this->card($workspace->fresh('dataSource.type')), 201);
    }

    public function update(Request $request, $id)
    {
        $workspace = $this->find($request, $id);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
        ]);

        $workspace->fill($data)->save();

        return response()->json($this->card($workspace->fresh('dataSource.type')));
    }

    public function destroy(Request $request, $id)
    {
        $workspace = $this->find($request, $id);

        DB::transaction(function () use ($workspace) {
            foreach ($workspace->dashboards as $dashboard) {
                $dashboard->widgets()->delete();
                $dashboard->delete();
            }

            foreach ($workspace->chats as $chat) {
                AiChatTask::query()->where('chat_id', $chat->id)->delete();
                AiChatMessage::query()->where('chat_id', $chat->id)->delete();
                $chat->delete();
            }

            foreach ($workspace->alerts as $alert) {
                File::deleteDirectory(
                    storage_path('app/company/'.$alert->company_id.'/alerts/'.$alert->id)
                );
            }

            $workspace->delete();
        });

        return response()->json(['message' => 'Рабочее пространство удалено.']);
    }

    public function show(Request $request, $id)
    {
        $workspace = $this->find($request, $id);

        return response()->json(
            $this->payload($workspace, $request->integer('dashboard') ?: null)
        );
    }

    public function byDashboard(Request $request, $dashboardId)
    {
        $dashboard = Dashboard::query()
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($dashboardId);

        $workspace = $dashboard->workspace_id
            ? $this->find($request, $dashboard->workspace_id)
            : $this->adopt($dashboard);

        return response()->json($this->payload($workspace, $dashboard->id));
    }

    public function byChat(Request $request, $chatId)
    {
        $chat = AiChat::query()
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($chatId);

        $workspace = $chat->workspace_id
            ? $this->find($request, $chat->workspace_id)
            : $this->adoptChat($chat);

        return response()->json($this->payload($workspace, null));
    }

    public function attachChat(Request $request, $id)
    {
        $workspace = $this->find($request, $id);

        $data = $request->validate([
            'dashboard_id' => [
                'nullable',
                Rule::exists('dashboards', 'id')->where('workspace_id', $workspace->id),
            ],
        ]);

        $dashboard = !empty($data['dashboard_id'])
            ? Dashboard::query()->where('workspace_id', $workspace->id)->find($data['dashboard_id'])
            : null;

        // Разговор живёт у дашборда: есть он — открываем, нет — заводим.
        $chat = $dashboard ? $this->dashboardChat($dashboard) : $workspace->chat();

        if ($chat) {
            return response()->json(['chat' => $this->chatPayload($chat)]);
        }

        if (!$workspace->data_source_id) {
            return response()->json([
                'message' => 'У пространства не задан источник данных — агенту не по чему считать.',
            ], 422);
        }

        $chat = AiChat::query()->create([
            'user_id' => $request->user()->id,
            'company_id' => $workspace->company_id,
            'workspace_id' => $workspace->id,
            'data_source_id' => $workspace->data_source_id,
            'title' => $dashboard?->name ?: $workspace->name,
        ]);

        $dashboard?->update(['chat_id' => $chat->id]);

        Log::info('Workspace: заведён разговор', [
            'workspace_id' => $workspace->id,
            'dashboard_id' => $dashboard?->id,
            'chat_id' => $chat->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['chat' => $this->chatPayload($chat)], 201);
    }

    /** Новый разговор пространства — не привязанный ни к какому дашборду. */
    public function storeChat(Request $request, $id)
    {
        $workspace = $this->find($request, $id);

        $data = $request->validate([
            'title' => 'nullable|string|max:255',
        ]);

        if (!$workspace->data_source_id) {
            return response()->json([
                'message' => 'У пространства не задан источник данных — агенту не по чему считать.',
            ], 422);
        }

        $chat = AiChat::query()->create([
            'user_id' => $request->user()->id,
            'company_id' => $workspace->company_id,
            'workspace_id' => $workspace->id,
            'data_source_id' => $workspace->data_source_id,
            'title' => $data['title'] ?? null ?: 'Новый чат — '.$workspace->name,
        ]);

        return response()->json([
            'chat' => $this->chatPayload($chat),
            'card' => $this->chatCard($chat->loadCount(['dashboards', 'messages'])),
        ], 201);
    }

    private function chatCard(AiChat $chat): array
    {
        return [
            'id' => $chat->id,
            'title' => $chat->title,
            'dashboards_count' => $chat->dashboards_count ?? 0,
            'messages_count' => $chat->messages_count ?? 0,
            'created_at' => $chat->created_at,
        ];
    }

    private function dashboardChat(Dashboard $dashboard): ?AiChat
    {
        if (!$dashboard->chat_id) {
            return null;
        }

        return AiChat::query()
            ->where('company_id', $dashboard->company_id)
            ->find($dashboard->chat_id);
    }

    private function payload(Workspace $workspace, ?int $dashboardId): array
    {
        $dashboards = $workspace->dashboards()
            ->withCount('widgets')

            ->orderByDesc('id')
            ->get();

        $current = $dashboardId && $dashboards->contains('id', $dashboardId)
            ? $dashboardId
            : $dashboards->first()?->id;

        $source = $workspace->dataSource;

        // Открыт конкретный дашборд — показываем его разговор (или его отсутствие:
        // тогда он заведётся с первым сообщением). На обзоре чат выбирают из списка `chats`.
        $openedDashboard = $dashboardId ? $dashboards->firstWhere('id', $dashboardId) : null;
        $chat = $openedDashboard ? $this->dashboardChat($openedDashboard) : null;

        return [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'description' => $workspace->description,
            ],
            'data_source' => $source ? [
                'id' => $source->id,
                'name' => $source->name,
                'format_label' => $source->format_label,
                'type' => $source->type->name ?? null,
            ] : null,
            'dashboards' => $dashboards->map(fn (Dashboard $dashboard) => [
                'id' => $dashboard->id,
                'name' => $dashboard->name,
                'status' => $dashboard->status,
                'origin' => $dashboard->origin,
                'widgets_count' => $dashboard->widgets_count,
                'chat_id' => $dashboard->chat_id,
                'created_at' => $dashboard->created_at,
            ])->values()->all(),
            'chat' => $chat ? $this->chatPayload($chat) : null,
            'chats' => $workspace->chats()
                ->withCount(['dashboards', 'messages'])
                ->orderByDesc('id')
                ->get()
                ->map(fn (AiChat $item) => $this->chatCard($item))
                ->values()
                ->all(),
            'current_dashboard_id' => $current,
        ];
    }

    private function card(Workspace $workspace): array
    {
        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'description' => $workspace->description,
            'dashboards_count' => $workspace->dashboards_count ?? $workspace->dashboards()->count(),
            'created_at' => $workspace->created_at,
            'data_source' => $workspace->dataSource ? [
                'id' => $workspace->dataSource->id,
                'name' => $workspace->dataSource->name,
                'type' => $workspace->dataSource->type->name ?? null,
            ] : null,
        ];
    }

    private function chatPayload(AiChat $chat): array
    {
        return [
            'id' => $chat->id,
            'title' => $chat->title,
            'suggestions' => DashboardSuggestion::query()
                ->where('data_source_id', $chat->data_source_id)
                ->orderBy('position')
                ->orderBy('id')
                ->get(),
        ];
    }

    private function adopt(Dashboard $dashboard): Workspace
    {
        $source = $dashboard->resolveDataSource(['type']);

        $workspace = Workspace::query()->create([
            'company_id' => $dashboard->company_id,
            'created_by' => $dashboard->created_by,
            'data_source_id' => $source?->id,
            'name' => $dashboard->name ?: 'Рабочее пространство',
        ]);

        $dashboard->workspace_id = $workspace->id;
        $dashboard->save();

        if ($dashboard->chat_id) {
            AiChat::query()->where('id', $dashboard->chat_id)->update(['workspace_id' => $workspace->id]);
        }

        return $workspace;
    }

    private function adoptChat(AiChat $chat): Workspace
    {
        $workspace = Workspace::query()->create([
            'company_id' => $chat->company_id,
            'created_by' => $chat->user_id,
            'data_source_id' => $chat->resolveDataSource(['type'])?->id,
            'name' => $chat->title ?: 'Рабочее пространство',
        ]);

        $chat->workspace_id = $workspace->id;
        $chat->save();

        Dashboard::query()->where('chat_id', $chat->id)->update(['workspace_id' => $workspace->id]);

        return $workspace;
    }

    private function find(Request $request, $id): Workspace
    {
        return Workspace::query()
            ->ofCompany($request->user()->company_id)
            ->with('dataSource.type')
            ->findOrFail($id);
    }
}
