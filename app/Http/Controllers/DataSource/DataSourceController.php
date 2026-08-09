<?php

namespace App\Http\Controllers\DataSource;

use App\Helpers\Ai\AiUsage;
use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\DataSource\DataSourceCreator;
use App\Helpers\DataSource\DataSourceRefresher;
use App\Http\Controllers\Controller;
use App\Jobs\DataSourceGroupingJob;
use App\Http\Requests\DataSource\StoreRequest;
use App\Models\AiChatMessage;
use App\Models\AiChatTask;
use App\Models\DataSource;
use App\Models\DataSourceGroup;
use App\Models\DataSourceTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Источники данных компании.
 *
 * Порядок работы: компания подключает источник здесь, а потом заводит на нём
 * сколько угодно чатов (см. ChatController::store). Раньше источник можно было
 * получить только вместе с новым чатом, и переиспользовать его было нельзя.
 *
 * Все запросы жёстко ограничены компанией текущего пользователя — источник
 * чужой компании не найдётся ни при каких правах.
 */
class DataSourceController extends Controller
{
    public function index(Request $request)
    {
        $sources = DataSource::query()
            ->ofCompany($request->user()->company_id)
            ->with(['type:id,name,label', 'creator:id,name'])
            ->withCount('chats')
            ->latest('id')
            ->get();

        return response()->json($sources);
    }

    public function show(Request $request, $id)
    {
        $source = $this->findForCompany($request, $id);

        $source->load([
            'type:id,name,label',
            'creator:id,name',
            // Чаты источника с их дашбордами — на странице источника это
            // основной список: «на этой базе уже спрашивали вот что».
            'chats' => fn ($query) => $query->latest('id')->with([
                'dashboards' => fn ($q) => $q->select('id', 'name', 'chat_id', 'status')->latest('id'),
            ]),
        ]);

        // Разобранная схема: сколько смысловых групп и таблиц нашлось.
        // Пусто — значит источник ещё ни разу не разбирали, разбор произойдёт
        // при первом же вопросе в чате.
        $groups = DataSourceGroup::query()
            ->where('data_source_id', $source->id)
            ->withCount('tables')
            ->orderBy('id')
            ->get(['id', 'name', 'description']);

        return response()->json([
            'data_source' => $source,
            'groups' => $groups,
            'tables_count' => DataSourceTable::query()
                ->where('data_source_id', $source->id)
                ->count(),
        ]);
    }

    public function store(StoreRequest $request)
    {
        $creator = new DataSourceCreator($request->user());

        if ($request->input('connection_type') === 'google_sheet') {
            $result = $creator->fromGoogleSheet(
                $request->input('sheet_url'),
                $request->input('name')
            );
        } elseif ($request->input('connection_type') === 'local') {
            if (!$request->hasFile('data_file')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Файл не был передан.',
                ], 422);
            }

            $result = $creator->fromFile(
                $request->file('data_file'),
                $request->input('type_id'),
                $request->input('version'),
                $request->input('name')
            );
        } else {
            $result = $creator->fromRemote([
                'type_id' => $request->input('type_id'),
                'name' => $request->input('name'),
                'host' => $request->input('host'),
                'port' => $request->input('port'),
                'database' => $request->input('database'),
                'username' => $request->input('username'),
                'password' => $request->input('password'),
                'version' => $request->input('version'),
            ]);
        }

        // Неудачное подключение — это ошибка запроса, а не успешный ответ
        // с флагом success:false: фронту незачем разбирать HTTP 200 вручную.
        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data_source' => $result['data_source'],
        ], 201);
    }

    /**
     * Шаг 2 мастера: что мы вообще нашли в источнике.
     *
     * Показывается пользователю до группировки, чтобы он убедился, что
     * подключились к той базе, и увидел объём работы. Тяжёлого анализа схемы
     * здесь нет — только список таблиц.
     */
    public function tables(Request $request, $id)
    {
        $source = $this->findForCompany($request, $id);

        try {
            $tables = (new ConnectionProviderRouter($source->id))->showTables();
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Не удалось прочитать список таблиц: ' . $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'tables' => $tables,
            // Группировка уже могла быть посчитана — например, если источник
            // добавляли раньше и мастер открыли повторно.
            'already_grouped' => DataSourceGroup::query()
                ->where('data_source_id', $source->id)
                ->exists(),
        ]);
    }

    /**
     * Шаг 3 мастера: ставит группировку таблиц в очередь.
     *
     * Отвечает сразу — сама работа идёт в DataSourceGroupingJob, а её ход
     * приходит на фронт событиями DataSourceGroupingProgress по каналу
     * data_source.{id}. Раньше группировка выполнялась прямо здесь и на
     * большой схеме упиралась в таймаут.
     *
     * Это та же DataSourceGrouping, которой потом пользуется генератор
     * дашбордов, поэтому работа не пропадает — первый дашборд построится
     * быстрее.
     */
    public function group(Request $request, $id)
    {
        $source = $this->findForCompany($request, $id);

        // Группировка — платная операция, при исчерпанном лимите не запускаем.
        if (AiUsage::limitReached($request->user()->company)) {
            return response()->json([
                'success' => false,
                'message' => 'Исчерпан месячный лимит на ИИ — группировка недоступна.',
                'usage' => AiUsage::summary($request->user()->company),
            ], 429);
        }

        // Повторный клик по кнопке не должен ставить вторую такую же задачу.
        if (in_array($source->grouping_status, ['queued', 'in_progress'], true)) {
            return response()->json([
                'success' => true,
                'status' => $source->grouping_status,
                'message' => 'Группировка уже выполняется.',
            ]);
        }

        $source->forceFill([
            'grouping_status' => 'queued',
            'grouping_stage' => 'В очереди',
            'grouping_message' => null,
        ])->save();

        dispatch(new DataSourceGroupingJob($source->id, $request->boolean('force')));

        return response()->json([
            'success' => true,
            'status' => 'queued',
            'message' => 'Группировка запущена.',
        ], 202);
    }

    /**
     * Обновление данных источника.
     *
     * Для Google-таблицы новый ввод не нужен — она перечитывается по
     * сохранённой ссылке. Для файла присылается новая версия того же формата.
     * Разобранная база перезаписывается по прежнему пути, поэтому все
     * построенные дашборды продолжают работать: у них меняются только цифры.
     */
    public function refresh(Request $request, $id)
    {
        $source = $this->findForCompany($request, $id);

        $request->validate([
            'data_file' => [
                'nullable',
                'file',
                'extensions:csv,txt,xls,xlsx,db,sqlite,sqlite3',
            ],
        ]);

        // Разбор большого файла не укладывается в стандартные 30 секунд.
        set_time_limit(600);

        $refresher = new DataSourceRefresher($source, $request->user());

        $result = $refresher->handle($request->file('data_file'));

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            // Состав таблиц мог измениться — фронт покажет, что именно,
            // и предложит пересобрать группировку.
            'schema_changed' => $result['schema_changed'] ?? false,
            'added_tables' => $result['added_tables'] ?? [],
            'removed_tables' => $result['removed_tables'] ?? [],
            'data_source' => $source->fresh()->load('type:id,name,label'),
        ]);
    }

    /**
     * Состояние группировки.
     *
     * Нужен как запасной путь к сокету: если событие потерялось или
     * пользователь открыл страницу уже после старта, мастер опрашивает
     * этот эндпоинт и показывает актуальное состояние.
     */
    public function groupingStatus(Request $request, $id)
    {
        $source = $this->findForCompany($request, $id);

        return response()->json([
            'status' => $source->grouping_status,
            'stage' => $source->grouping_stage,
            'message' => $source->grouping_message,
            'groups_count' => DataSourceGroup::query()
                ->where('data_source_id', $source->id)
                ->count(),
        ]);
    }

    /**
     * Правится только то, что можно править безопасно: имя, версия и
     * реквизиты внешнего подключения. Тип и разобранный файл менять нельзя —
     * на них уже завязаны построенные дашборды.
     */
    public function update(Request $request, $id)
    {
        $source = $this->findForCompany($request, $id);

        $rules = [
            'name' => 'sometimes|required|string|max:255',
            'version' => 'sometimes|nullable|string|max:20',
        ];

        if ($source->connection_type === 'remote') {
            $rules += [
                'host' => 'sometimes|required|string',
                'port' => 'sometimes|required|integer',
                'database' => 'sometimes|required|string',
                'username' => 'sometimes|required|string',
                // Пустой пароль означает «оставить прежний»: наружу мы его
                // не отдаём, и форма редактирования его не знает.
                'password' => 'sometimes|nullable|string',
            ];
        }

        $data = $request->validate($rules);

        if (array_key_exists('password', $data) && ($data['password'] ?? '') === '') {
            unset($data['password']);
        }

        $source->fill($data)->save();

        return response()->json([
            'success' => true,
            'data_source' => $source->fresh()->load('type:id,name,label'),
        ]);
    }

    /**
     * Удаление источника вместе со всем, что на нём построено: чатами,
     * их дашбордами и разобранной схемой. Без источника всё это неработоспособно,
     * поэтому оставлять «висящие» чаты бессмысленно — фронт предупреждает
     * пользователя количеством затрагиваемых чатов из index/show.
     */
    public function destroy(Request $request, $id)
    {
        $source = $this->findForCompany($request, $id);

        DB::transaction(function () use ($source) {
            foreach ($source->chats as $chat) {
                foreach ($chat->dashboards as $dashboard) {
                    $dashboard->widgets()->delete();
                    $dashboard->delete();
                }

                AiChatTask::query()->where('chat_id', $chat->id)->delete();
                AiChatMessage::query()->where('chat_id', $chat->id)->delete();
                $chat->delete();
            }

            DataSourceTable::query()->where('data_source_id', $source->id)->delete();
            DataSourceGroup::query()->where('data_source_id', $source->id)->delete();

            // Разобранный файл источника занимает место и после удаления
            // записи уже никому не нужен.
            if ($source->isFileBased() && $source->path && is_file($source->path)) {
                @unlink($source->path);
            }

            $source->delete();
        });

        return response()->json(['message' => 'Источник данных удалён.']);
    }

    private function findForCompany(Request $request, $id): DataSource
    {
        return DataSource::query()
            ->ofCompany($request->user()->company_id)
            ->findOrFail($id);
    }
}
