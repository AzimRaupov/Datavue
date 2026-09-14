<?php

namespace App\Http\Controllers\DataSource;

use App\Helpers\Ai\AiUsage;
use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\DataSource\DataSourceCreator;
use App\Helpers\DataSource\DataSourceRefresher;
use App\Helpers\DataSource\SourceSchema;
use App\Http\Controllers\Controller;
use App\Jobs\DataSourceGroupingJob;
use App\Http\Requests\DataSource\StoreRequest;
use App\Models\AiChatMessage;
use App\Models\AiChatTask;
use App\Models\Alert;
use App\Models\DataSource;
use App\Models\DataSourceGroup;
use App\Models\DataSourceTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        ]);

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

            'already_grouped' => DataSourceGroup::query()
                ->where('data_source_id', $source->id)
                ->exists(),
        ]);
    }

    public function group(Request $request, $id)
    {
        $source = $this->findForCompany($request, $id);

        if (AiUsage::limitReached($request->user()->company)) {
            return response()->json([
                'success' => false,
                'message' => 'Исчерпан месячный лимит на ИИ — группировка недоступна.',
                'usage' => AiUsage::summary($request->user()->company),
            ], 429);
        }

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

        set_time_limit(600);

        $refresher = new DataSourceRefresher($source, $request->user());

        $result = $refresher->handle($request->file('data_file'));

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        SourceSchema::forget($source->id);

        return response()->json([
            'success' => true,
            'message' => $result['message'],

            'schema_changed' => $result['schema_changed'] ?? false,
            'added_tables' => $result['added_tables'] ?? [],
            'removed_tables' => $result['removed_tables'] ?? [],
            'data_source' => $source->fresh()->load('type:id,name,label'),
        ]);
    }

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

                'password' => 'sometimes|nullable|string',
            ];
        }

        $data = $request->validate($rules);

        if (array_key_exists('password', $data) && ($data['password'] ?? '') === '') {
            unset($data['password']);
        }

        $source->fill($data)->save();

        SourceSchema::forget($source->id);

        return response()->json([
            'success' => true,
            'data_source' => $source->fresh()->load('type:id,name,label'),
        ]);
    }

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

            Alert::query()
                ->where('data_source_id', $source->id)
                ->update([
                    'is_active' => false,
                    'disabled_reason' => 'Источник данных удалён.',
                ]);

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
