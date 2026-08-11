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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Чаты с ИИ-агентом.
 *
 * Чат заводится НА уже подключённом источнике данных: компания сначала
 * добавляет источник (см. DataSourceController), а потом создаёт под него
 * столько чатов, сколько нужно. Раньше эти два действия были склеены — чат
 * создавался только вместе с новым источником, и одну и ту же базу приходилось
 * подключать заново под каждый вопрос.
 */
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

        // Список чатов конкретного источника — на его странице.
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

        // Варианты дашбордов лежат на источнике, но чат — единственное место,
        // где они показываются, поэтому отдаём их вместе с чатом. При
        // перезагрузке страницы предложения не теряются.
        $suggestions = DashboardSuggestion::query()
            ->where('data_source_id', $chat->data_source_id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json(
            $chat->toArray() + ['suggestions' => $suggestions]
        );
    }

    /**
     * Новый чат на существующем источнике.
     *
     * Источник больше не создаётся здесь: приходит его id, и остаётся только
     * проверить, что он принадлежит той же компании.
     */
    public function store(StoreRequest $request)
    {
        $user = $request->user();

        $dataSource = DataSource::query()
            ->ofCompany($user->company_id)
            ->find($request->input('data_source_id'));

        // Отдельная проверка, а не exists-правило: сообщение об источнике чужой
        // компании не должно отличаться от сообщения о несуществующем.
        if (!$dataSource) {
            return response()->json([
                'success' => false,
                'message' => 'Источник данных не найден.',
            ], 404);
        }

        $chat = AiChat::create([
            'user_id'        => $user->id,
            'company_id'     => $user->company_id,
            'data_source_id' => $dataSource->id,
            'title'          => $request->input('title') ?: 'Новый чат — ' . $dataSource->name,
        ]);

        // Варианты дашбордов готовятся ЗДЕСЬ, а не в фоне: фронт держит
        // кнопку в состоянии загрузки и открывает чат уже с готовыми
        // предложениями. Первый вызов на источнике заодно строит группировку
        // таблиц, поэтому может занять до минуты; для второго чата на том же
        // источнике всё берётся из базы и отвечает мгновенно.
        //
        // Сбой генерации намеренно не роняет запрос — чат создан, просто
        // откроется без предложений (см. DashboardSuggestionGenerator).
        // При исчерпанном лимите чат всё равно создаём — просто без вариантов:
        // блокировать создание чата из-за необязательной подсказки неправильно.
        $suggestions = AiUsage::limitReached($user->company)
            ? collect()
            : (new DashboardSuggestionGenerator($dataSource))->handle();

        return response()->json([
            'success' => true,
            'message' => 'Чат создан.',
            'chat' => $chat->load('dataSource:id,name,type_id,origin_format', 'dataSource.type:id,name,label'),
            'suggestions' => $suggestions,
        ], 201);
    }

    /**
     * Переименование чата.
     */
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

    /**
     * Удаление чата вместе с его дашбордами и перепиской.
     *
     * Источник данных при этом НЕ трогается: он принадлежит компании, а не
     * чату, и на нём могут работать другие чаты. Раньше здесь стояло
     * DataSource::where('chat_id', ...)->delete() — вместе с чатом уносило и базу.
     */
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
