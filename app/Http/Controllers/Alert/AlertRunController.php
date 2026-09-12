<?php

namespace App\Http\Controllers\Alert;

use App\Helpers\Alert\AlertChecker;
use App\Helpers\Alert\AlertCodeRun;
use App\Helpers\Alert\AlertCondition;
use App\Helpers\Alert\AlertQueryBuilder;
use App\Helpers\Alert\AlertRunner;
use App\Helpers\DataSource\SourceSchema;
use App\Helpers\Widget\WidgetCodeInspector;
use App\Helpers\Widget\WidgetQueryComposer;
use App\Helpers\Widget\WidgetSpecValidator;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\AlertCheckerHistory;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Всё, что происходит с содержимым алерта, кроме самого CRUD: схема источника
 * для конструктора, черновая проверка условия до сохранения, «проверить
 * сейчас» у сохранённого алерта и его история.
 */
class AlertRunController extends Controller
{
    /**
     * Схема источника пространства + словари конструктора — тот же набор,
     * что DashboardBuilderController::schema() отдаёт конструктору виджетов.
     */
    public function schema(Request $request, $workspaceId)
    {
        $workspace = $this->findWorkspace($request, $workspaceId);
        $dataSource = $workspace->dataSource;

        if (!$dataSource) {
            return response()->json(['message' => 'У пространства не задан источник данных.'], 422);
        }

        try {
            $tables = SourceSchema::tables($dataSource);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Не удалось прочитать схему источника: '.$e->getMessage(),
            ], 422);
        }

        try {
            $relations = SourceSchema::relations($dataSource, array_column($tables, 'name'));
        } catch (Throwable) {
            $relations = [];
        }

        return response()->json([
            'data_source' => ['id' => $dataSource->id, 'name' => $dataSource->name],
            'tables' => $tables,
            'relations' => $relations,
            'aggregates' => WidgetQueryComposer::AGGREGATES,
            'join_types' => WidgetQueryComposer::JOIN_TYPES,
            'grains' => WidgetQueryComposer::GRAINS,
            'operators' => WidgetQueryComposer::OPERATORS,
            'default_limit' => WidgetQueryComposer::DEFAULT_LIMIT,
            'condition_operators' => AlertCondition::OPERATORS,
            'on_empty_options' => AlertCondition::ON_EMPTY,
            'intervals' => config('alerts.intervals'),
        ]);
    }

    /**
     * Черновая проверка условия до сохранения: собранный SQL (или вывод
     * Python), строки результата и вердикт «сработало бы».
     *
     * Ничего не пишет ни в алерт, ни в историю, и не рассылает писем —
     * это именно «покажи, что получится», а не проверка.
     */
    public function preview(Request $request, $workspaceId)
    {
        $workspace = $this->findWorkspace($request, $workspaceId);
        $dataSource = $workspace->dataSource;

        if (!$dataSource) {
            return response()->json(['message' => 'У пространства не задан источник данных.'], 422);
        }

        $data = $request->validate([
            'mode' => ['required', Rule::in([Alert::MODE_BUILDER, Alert::MODE_SQL, Alert::MODE_PYTHON])],
            'builder' => 'nullable|array',
            'query' => 'nullable|string|max:20000',
            'code' => 'nullable|string|max:'.WidgetCodeInspector::MAX_LENGTH,
            'condition' => 'nullable|array',
        ]);

        if ($data['mode'] !== Alert::MODE_BUILDER && !$request->user()->can('write alert code')) {
            abort(403, 'Нужно право «Писать SQL и Python-условия алертов».');
        }

        $draft = new Alert($data);

        try {
            if ($data['mode'] === Alert::MODE_PYTHON) {
                return response()->json($this->previewPython($draft, $dataSource));
            }

            return response()->json($this->previewSql($draft, $dataSource, $data['condition'] ?? []));
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => WidgetSpecValidator::cleanDatabaseError($e->getMessage()),
            ], 422);
        }
    }

    private function previewSql(Alert $draft, $dataSource, array $condition): array
    {
        $run = (new AlertRunner())->run($draft, $dataSource, (int) config('alerts.sample_rows'));

        // Тот же разрешатель, что и при сохранении: в builder-режиме автор
        // выбирает метрику по номеру, а не имя колонки — иначе превью может
        // разойтись с тем, что реально сохранится (см. AlertController::
        // checkCondition про историю бага «нет колонки»).
        if ($condition !== []) {
            $condition = AlertQueryBuilder::resolveConditionColumn($condition, $draft, $dataSource);
        }

        $evaluation = $condition === []
            ? ['triggered' => null, 'value' => null, 'message' => null]
            : AlertCondition::evaluate($condition, $run['row_count'], $run['first_row']);

        return [
            'ok' => true,
            'sql' => $run['sql'],
            'row_count' => $run['row_count'],
            'rows' => $run['sample'],
            'triggered' => $evaluation['triggered'],
            'value' => $evaluation['value'],
        ];
    }

    private function previewPython(Alert $draft, $dataSource): array
    {
        $inspection = (new WidgetCodeInspector())->inspect($draft->code);

        if (!$inspection['ok']) {
            return ['ok' => false, 'message' => implode(' ', $inspection['errors'])];
        }

        $result = (new AlertCodeRun())->run($draft, $dataSource, (int) config('alerts.python_timeout'));

        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['error']];
        }

        return [
            'ok' => true,
            'triggered' => $result['triggered'],
            'value' => $result['value'],
            'message' => $result['message'],
            'rows' => array_slice($result['rows'] ?? [], 0, (int) config('alerts.sample_rows')),
        ];
    }

    /**
     * «Проверить сейчас»: тот же путь, что и по расписанию, но без письма —
     * иначе кнопка ради любопытства могла бы разбудить всю почту компании.
     */
    public function run(Request $request, $id, AlertChecker $checker)
    {
        $alert = $this->find($request, $id);

        $check = $checker->check($alert, AlertCheckerHistory::SOURCE_MANUAL, notify: false);

        return response()->json([
            'status' => $check->status,
            'value' => $check->value,
            'matched_rows' => $check->matched_rows,
            'message' => $check->message,
            'error' => $check->error,
            'rows' => $check->payload,
            'duration_ms' => $check->duration_ms,
            'csv_url' => $check->csv_url,
            'csv_row_count' => $check->csv_row_count,
        ]);
    }

    public function history(Request $request, $id)
    {
        $alert = $this->find($request, $id);

        $history = $alert->history()
            ->orderByDesc('id')
            ->paginate($request->integer('per_page') ?: 25);

        return response()->json($history);
    }

    private function find(Request $request, $id): Alert
    {
        return Alert::query()
            ->ofCompany($request->user()->company_id)
            ->with('dataSource')
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
