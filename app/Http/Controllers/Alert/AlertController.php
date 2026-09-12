<?php

namespace App\Http\Controllers\Alert;

use App\Helpers\Alert\AlertQueryBuilder;
use App\Helpers\Widget\WidgetCodeInspector;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * CRUD алертов рабочего пространства.
 *
 * Условие проверяется структурно ДО сохранения (SQL собирается/проходит
 * ReadOnlySqlGuard, Python — AST-инспектор), но не выполняется на источнике:
 * это делает отдельный эндпоинт «Проверить» (AlertRunController::preview) —
 * сохранение не должно зависеть от того, доступна ли в этот момент база
 * клиента.
 */
class AlertController extends Controller
{
    /**
     * Алерты пространства.
     */
    public function index(Request $request, $workspaceId)
    {
        $workspace = $this->findWorkspace($request, $workspaceId);

        $alerts = $workspace->alerts()->orderByDesc('id')->get();

        return response()->json($alerts->map(fn (Alert $alert) => $this->card($alert))->values());
    }

    public function store(Request $request, $workspaceId)
    {
        $workspace = $this->findWorkspace($request, $workspaceId);

        if (!$workspace->data_source_id) {
            return response()->json([
                'message' => 'У пространства не задан источник данных — алерту не по чему считать.',
            ], 422);
        }

        $limit = (int) config('alerts.max_per_company');

        if ($limit > 0 && Alert::query()->where('company_id', $workspace->company_id)->count() >= $limit) {
            return response()->json([
                'message' => "Достигнут предел алертов компании ({$limit}).",
            ], 422);
        }

        $data = $this->validated($request, $workspace->company_id);
        $this->authorizeCodeMode($request, $data['mode']);

        if ($error = $this->checkCondition($data, $workspace)) {
            return response()->json(['message' => $error], 422);
        }

        // checkCondition() дописал в $data['condition']['column'] настоящее
        // имя колонки результата (для builder — по выбранной метрике) —
        // ниже сохраняется уже эта, разрешённая версия.
        $alert = DB::transaction(fn () => Alert::query()->create($data + [
            'company_id' => $workspace->company_id,
            'workspace_id' => $workspace->id,
            'data_source_id' => $workspace->data_source_id,
            'created_by' => $request->user()->id,
            'state' => Alert::STATE_UNKNOWN,
            // Первая проверка — на ближайшем тике планировщика, а не через
            // полный интервал: иначе только что созданный алерт молчал бы
            // час, прежде чем хоть раз на что-то посмотреть.
            'next_check_at' => now(),
        ]));

        // fresh(): столбцы, отсутствовавшие в $data (например, is_active,
        // когда его не передали), заполнились дефолтом на стороне СУБД —
        // в объекте create() их ещё нет.
        return response()->json($this->card($alert->fresh()), 201);
    }

    public function show(Request $request, $id)
    {
        return response()->json($this->card($this->find($request, $id)));
    }

    public function update(Request $request, $id)
    {
        $alert = $this->find($request, $id);

        $data = $this->validated($request, $alert->company_id, $alert);
        $this->authorizeCodeMode($request, $data['mode']);

        if ($error = $this->checkCondition($data, $alert->workspace)) {
            return response()->json(['message' => $error], 422);
        }

        // checkCondition() дописал в $data['condition']['column'] настоящее
        // имя колонки результата — ниже сохраняется уже она.

        // Правка условия сбрасывает состояние: старое "firing"/"error" было
        // про прежнее условие и вводит в заблуждение про новое, пока его
        // ещё ни разу не проверили.
        $data['state'] = Alert::STATE_UNKNOWN;
        $data['consecutive_failures'] = 0;
        $data['next_check_at'] = now();

        $alert->fill($data)->save();

        return response()->json($this->card($alert->fresh()));
    }

    public function destroy(Request $request, $id)
    {
        $alert = $this->find($request, $id);

        // История удаляется каскадом на уровне БД (alert_checker_histories.
        // alert_id ON DELETE CASCADE), а вот CSV на диске сам не пропадёт —
        // весь каталог проверок этого алерта убирается одним махом, а не
        // построчно по каждой записи истории.
        File::deleteDirectory(
            storage_path('app/company/'.$alert->company_id.'/alerts/'.$alert->id)
        );

        $alert->delete();

        return response()->json(['message' => 'Алерт удалён.']);
    }

    /**
     * Включение/выключение без похода в форму редактирования.
     *
     * Включение сбрасывает disabled_reason и счётчик ошибок: алерт, которого
     * коснулись руками, заслуживает чистого старта, а не немедленного
     * повторного авто-отключения на старом счётчике.
     */
    public function toggle(Request $request, $id)
    {
        $alert = $this->find($request, $id);

        $alert->is_active = !$alert->is_active;

        if ($alert->is_active) {
            $alert->disabled_reason = null;
            $alert->consecutive_failures = 0;
            $alert->next_check_at = now();
        }

        $alert->save();

        return response()->json($this->card($alert));
    }

    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $companyId, ?Alert $existing = null): array
    {
        $mode = $request->input('mode', $existing?->mode ?? Alert::MODE_BUILDER);

        $rules = [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'mode' => ['required', Rule::in([Alert::MODE_BUILDER, Alert::MODE_SQL, Alert::MODE_PYTHON])],
            'interval_minutes' => ['required', 'integer', Rule::in(config('alerts.intervals'))],
            'is_active' => 'sometimes|boolean',
            'repeat_after_minutes' => ['sometimes', 'integer', 'min:'.max(1, (int) config('alerts.min_interval_minutes'))],
            'notify_on_resolve' => 'sometimes|boolean',

            'recipients' => 'required|array',
            'recipients.users' => 'sometimes|array',
            'recipients.users.*' => [
                'integer',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],
            'recipients.emails' => 'sometimes|array',
            'recipients.emails.*' => 'email:filter|max:255',
        ];

        if ($mode === Alert::MODE_BUILDER) {
            $rules['builder'] = 'required|array';
        } elseif ($mode === Alert::MODE_SQL) {
            $rules['query'] = 'required|string|max:20000';
        } else {
            $rules['code'] = 'required|string|max:'.WidgetCodeInspector::MAX_LENGTH;
        }

        if ($mode !== Alert::MODE_PYTHON) {
            $rules['condition'] = 'required|array';
            $rules['condition.kind'] = ['required', Rule::in(['rows', 'value'])];
            $rules['condition.op'] = ['required', Rule::in(\App\Helpers\Alert\AlertCondition::OPERATORS)];
            $rules['condition.threshold'] = 'required|numeric';
            $rules['condition.on_empty'] = ['required', Rule::in(\App\Helpers\Alert\AlertCondition::ON_EMPTY)];

            if ($mode === Alert::MODE_SQL) {
                // В своём SQL колонку называет сам автор — платформе она
                // известна только с его слов.
                $rules['condition.column'] = 'required_if:condition.kind,value|nullable|string|max:255';
            } else {
                // В конструкторе колонку не называют — выбирают метрику
                // по номеру, а имя колонки в SQL решает сам composer
                // (см. AlertQueryBuilder::resolveConditionColumn).
                $rules['condition.metric_index'] = 'required_if:condition.kind,value|nullable|integer|min:0';
            }
        }

        $data = $request->validate($rules);

        $maxRecipients = (int) config('alerts.max_recipients');
        $recipientCount = count($data['recipients']['users'] ?? []) + count($data['recipients']['emails'] ?? []);

        if ($recipientCount === 0) {
            abort(422, 'Укажите хотя бы одного получателя.');
        }

        if ($maxRecipients > 0 && $recipientCount > $maxRecipients) {
            abort(422, "Получателей больше, чем разрешено ({$maxRecipients}).");
        }

        // Поля режимов, которые сейчас не выбраны, обнуляются явно: иначе
        // правка с 'sql' на 'builder' оставила бы старый текст запроса
        // висеть в базе и путать в списке причин, по которым сработал алерт.
        $data['builder'] = $mode === Alert::MODE_BUILDER ? $data['builder'] : null;
        $data['query'] = $mode === Alert::MODE_SQL ? $data['query'] : null;
        $data['code'] = $mode === Alert::MODE_PYTHON ? $data['code'] : null;
        $data['condition'] = $mode === Alert::MODE_PYTHON ? null : $data['condition'];

        return $data;
    }

    /**
     * SQL и Python выполняются на сервере — писать их можно только с правом
     * 'write alert code', ровно как у ручного виджета. Конструктор метрик
     * это право не требует: SQL за автора собирает платформа.
     */
    private function authorizeCodeMode(Request $request, string $mode): void
    {
        if ($mode !== Alert::MODE_BUILDER && !$request->user()->can('write alert code')) {
            abort(403, 'Нужно право «Писать SQL и Python-условия алертов».');
        }
    }

    /**
     * Структурная проверка условия до сохранения: SQL собирается и проходит
     * ReadOnlySqlGuard, Python — AST-инспектор. Выполнение на источнике сюда
     * не входит — за него отвечает отдельная кнопка «Проверить».
     *
     * Заодно разрешает условие «по значению» в режиме builder: автор выбирает
     * МЕТРИКУ (её номер), а не имя колонки — имя решает сам composer, и его
     * дописывает сюда, в $data['condition']['column'], эта же проверка.
     * Раньше имя гадал фронт, и оно расходилось с тем, что composer в итоге
     * подставлял алиасом (дефолт вида «Сумма total_usd», а не «total_usd»),
     * из-за чего сохранённое условие било по несуществующей колонке.
     *
     * @return string|null Сообщение об ошибке, если проверка не пройдена
     */
    private function checkCondition(array &$data, Workspace $workspace): ?string
    {
        $dataSource = $workspace->dataSource;

        if (!$dataSource) {
            return 'У пространства не задан источник данных.';
        }

        if ($data['mode'] === Alert::MODE_PYTHON) {
            $inspection = (new WidgetCodeInspector())->inspect($data['code']);

            return $inspection['ok'] ? null : implode(' ', $inspection['errors']);
        }

        $alert = new Alert($data);

        try {
            AlertQueryBuilder::sql($alert, $dataSource);

            if ($data['condition']) {
                $data['condition'] = AlertQueryBuilder::resolveConditionColumn($data['condition'], $alert, $dataSource);
            }

            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        } catch (Throwable $e) {
            return 'Не удалось прочитать схему источника: '.$e->getMessage();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Alert $alert): array
    {
        $recipients = $alert->recipients ?? [];

        return [
            'id' => $alert->id,
            'workspace_id' => $alert->workspace_id,
            'title' => $alert->title,
            'description' => $alert->description,
            'mode' => $alert->mode,
            'builder' => $alert->builder,
            'query' => $alert->query,
            'code' => $alert->code,
            'condition' => $alert->condition,
            'interval_minutes' => $alert->interval_minutes,
            'is_active' => $alert->is_active,
            'state' => $alert->state,
            'disabled_reason' => $alert->disabled_reason,
            'next_check_at' => $alert->next_check_at,
            'last_checked_at' => $alert->last_checked_at,
            'last_triggered_at' => $alert->last_triggered_at,
            'last_notified_at' => $alert->last_notified_at,
            'consecutive_failures' => $alert->consecutive_failures,
            'recipients' => [
                'users' => $recipients['users'] ?? [],
                'emails' => $recipients['emails'] ?? [],
                // Имена сотрудников для отображения в списке — без похода
                // фронта за отдельным справочником пользователей.
                'user_names' => empty($recipients['users']) ? [] : User::query()
                    ->whereIn('id', $recipients['users'])
                    ->pluck('name', 'id'),
            ],
            'repeat_after_minutes' => $alert->repeat_after_minutes,
            'notify_on_resolve' => $alert->notify_on_resolve,
            'created_at' => $alert->created_at,
        ];
    }

    private function find(Request $request, $id): Alert
    {
        return Alert::query()
            ->ofCompany($request->user()->company_id)
            ->with('workspace')
            ->findOrFail($id);
    }

    private function findWorkspace(Request $request, $id): Workspace
    {
        return Workspace::query()
            ->ofCompany($request->user()->company_id)
            ->with('dataSource.type')
            ->findOrFail($id);
    }
}
