<?php

namespace App\Http\Controllers\Chat;

use App\Helpers\DataSource\ConnectRemoteDb;
use App\Helpers\DataSource\DataSourceRouter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StoreRequest;
use App\Jobs\DataHandlerJob;
use App\Jobs\DashboardGeneratorJob;

use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\AiChatTask;
use App\Models\Dashboard;
use App\Models\DataSource;
use App\Models\DataSourceType;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use function Pest\Laravel\json;

class ChatController extends Controller
{
    public function index(Request $request){

        $user = auth()->user();

        $chats = AiChat::where('company_id', $user->company->id)
            ->with([
                'dashboards' => fn ($query) => $query
                    ->latest()
                    ->limit(3)
            ])
            ->get();

        return response()->json($chats);
    }

    public function show($id){

        $user = auth()->user();

        $chat = AiChat::query()
            ->where('id', $id)
            ->where('company_id', $user->company->id)
            ->with([
                'dashboards' => function ($query) {
                    $query->select('id', 'name', 'chat_id');
                },

                ])
            ->first();

        return $chat;

    }

    public function store(StoreRequest $request)
    {
        $user = auth()->user();

        if ($request->input('connection_type') === 'local' && !$request->hasFile('data_file')) {
            return [
                'success' => false,
                'message' => 'Файл не был передан.',
            ];
        }

        $storedFullPath = null;

        try {
            $result = DB::transaction(function () use ($request, $user, &$storedFullPath) {

                $chat = AiChat::create([
                    'user_id'    => $user->id,
                    'company_id' => $user->company->id,
                ]);
                $types = DataSourceType::query()->pluck('id', 'name')->toArray();

                $connectionType = $request->input('connection_type');

                if ($connectionType === 'local') {

                    $file = $request->file('data_file');
                    $path = $user->company->id . '/chats/' . $chat->id . '/data';
                    $name = uniqid('', true) . '.' . $file->getClientOriginalExtension();

                    $storedPath = Storage::disk('company')->putFileAs($path, $file, $name);
                    $storedFullPath = Storage::disk('company')->path($storedPath);

                    $upload = UploadedFile::create([
                        'company_id'    => $user->company->id,
                        'chat_id'       => $chat->id,
                        'original_name' => $file->getClientOriginalName(),
                        'file_path'     => $storedFullPath,
                        'file_type'     => strtolower($file->getClientOriginalExtension()),
                        'file_size'     => $file->getSize(),
                    ]);

                    $dataSourceRouter = new DataSourceRouter(
                        $chat->id,
                        $upload->id,
                        $user->id,
                        $request->input('type_id')
                    );

                    $handlerResult = $dataSourceRouter->handle();

                    if (!$handlerResult['success']) {
                        throw new \RuntimeException($handlerResult['message']);
                    }

                    $extraction = $handlerResult['extraction'];
                    $connection = $handlerResult['connection'] ?? null;

                    if ($connection) {
                        // это был .sql дамп, импортированный в реальную mysql-базу —
                        // сохраняем источник как remote, а не local
                        DataSource::query()->create([
                            'company_id'      => $chat->company_id,
                            'chat_id'         => $chat->id,
                            'type_id'         => $types[$connection['type_database']],
                            'extracted_id'    => $extraction->id,
                            'name'            => $upload->original_name,
                            'connection_type' => 'remote',
                            'version'         => $request->input('version'),
                            'host'            => $connection['host'],
                            'port'            => $connection['port'],
                            'database'        => $connection['database'],
                            'username'        => $connection['username'],
                            'password'        => $connection['password'],
                            'path'            => null,
                        ]);
                    } else {
                        DataSource::query()->create([
                            'company_id'      => $chat->company_id,
                            'chat_id'         => $chat->id,
                            'type_id'         => 1,
                            'extracted_id'    => $extraction->id,
                            'name'            => $upload->original_name,
                            'connection_type' => $connectionType,
                            'version'         => $request->input('version'),
                            'path'            => $extraction->data_path,
                        ]);
                    }

                    return ['chat' => $chat, 'success' => true, 'message' => $handlerResult['message']];
                }

                if ($connectionType === 'remote') {

                    $type = DataSourceType::query()->find($request->input('type_id'));

                    $remoteDb = new ConnectRemoteDb(
                        $request->input('host'),
                        $request->input('port'),
                        $request->input('database'),
                        $type->name ?? null,
                        $request->input('username'),
                        $request->input('password')
                    );

                    $checkResult = $remoteDb->check();

                    if (!$checkResult['success']) {
                        throw new \RuntimeException($checkResult['message']);
                    }

                    DataSource::query()->create([
                        'company_id'      => $chat->company_id,
                        'chat_id'         => $chat->id,
                        'type_id'         => $request->input('type_id'),
                        'name'            => $request->input('database'),
                        'host'            => $request->input('host'),
                        'port'            => $request->input('port'),
                        'database'        => $request->input('database'),
                        'username'        => $request->input('username'),
                        'password'        => $request->input('password'),
                        'connection_type' => $connectionType,
                        'version'         => $request->input('version') ?: null,
                    ]);

                    return ['chat' => $chat, 'success' => true, 'message' => $checkResult['message']];
                }

                throw new \RuntimeException('Неизвестный connection_type.');
            });

            return $result;

        } catch (\Throwable $e) {

            if ($storedFullPath && file_exists($storedFullPath)) {
                @unlink($storedFullPath);
            }

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

}


