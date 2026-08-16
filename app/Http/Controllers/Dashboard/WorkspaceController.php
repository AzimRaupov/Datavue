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
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Рабочие пространства.
 *
 * Пространство — это задача, над которой работают: свой источник данных, свои
 * дашборды и один разговор с агентом. До него дашборды принадлежали чату, из
 * которого выросли, а собранные руками — ничему; поговорить с агентом про свой
 * дашборд было нельзя, а «все дашборды одной базы» — не задача, а свалка.
 *
 * Всё, что делает страница пространства, делается над ОДНИМ открытым дашбордом,
 * поэтому и чат, и конструктор всегда говорят об одном и том же.
 */
class WorkspaceController extends Controller
{
    /**
     * Список пространств компании.
     */
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
            // Источник обязателен: дашборды внутри должны быть по чему считать,
            // и агент в разговоре смотрит на него же.
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

    /**
     * Удаление пространства вместе с его дашбордами и разговором.
     *
     * Источник данных при этом не трогается: он принадлежит компании, и на нём
     * работают другие пространства.
     */
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

            $workspace->delete();
        });

        return response()->json(['message' => 'Рабочее пространство удалено.']);
    }

    /**
     * Пространство целиком: источник, его дашборды и разговор.
     *
     * Открытый дашборд можно назвать явно (?dashboard=id) — иначе берётся
     * последний: перегенерация не правит дашборд, а создаёт следующую версию,
     * и открывать надо именно её.
     */
    public function show(Request $request, $id)
    {
        $workspace = $this->find($request, $id);

        return response()->json(
            $this->payload($workspace, $request->integer('dashboard') ?: null)
        );
    }

    /**
     * Пространство по дашборду — для ссылок, которые знают только его.
     */
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

    /**
     * Пространство по чату — для ссылок из прежней версии интерфейса.
     */
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

    /**
     * Заводит разговор пространству.
     *
     * Разговор один на пространство: дашборды внутри — про одну задачу, и
     * держать под каждый свою переписку значит терять контекст ровно там,
     * где он нужен. Именно это делает дашборд, собранный руками, обсуждаемым:
     * до пространств агент умел править только то, что сам и построил.
     */
    public function attachChat(Request $request, $id)
    {
        $workspace = $this->find($request, $id);

        if ($chat = $workspace->chat()) {
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
            'title' => $workspace->name,
        ]);

        Log::info('Workspace: заведён разговор', [
            'workspace_id' => $workspace->id,
            'chat_id' => $chat->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['chat' => $this->chatPayload($chat)], 201);
    }

    // -----------------------------------------------------------------
    // Сборка ответа
    // -----------------------------------------------------------------

    private function payload(Workspace $workspace, ?int $dashboardId): array
    {
        $dashboards = $workspace->dashboards()
            ->withCount('widgets')
            // Новые сверху: перегенерация создаёт следующую версию дашборда,
            // и актуальная должна быть первой.
            ->orderByDesc('id')
            ->get();

        $current = $dashboardId && $dashboards->contains('id', $dashboardId)
            ? $dashboardId
            : $dashboards->first()?->id;

        $source = $workspace->dataSource;
        $chat = $workspace->chat();

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
                'created_at' => $dashboard->created_at,
            ])->values()->all(),
            'chat' => $chat ? $this->chatPayload($chat) : null,
            'current_dashboard_id' => $current,
        ];
    }

    /**
     * @return array{id: int, name: string, ...}
     */
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

    /**
     * Разговор вместе с подсказками для пустого чата.
     *
     * Подсказки только читаются: генерировать их здесь нельзя — это обращение
     * к модели, а пространство открывается при каждом переключении дашборда.
     */
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

    /**
     * Пространство для дашборда, у которого его почему-то нет.
     *
     * Переезд раскладывает по пространствам всё, что было, но дашборд мог
     * появиться и в обход — например, из задачи, поставленной в очередь до
     * обновления. Тогда заводим пространство здесь: остаться без него дашборд
     * не должен, иначе открыть его будет негде.
     */
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
