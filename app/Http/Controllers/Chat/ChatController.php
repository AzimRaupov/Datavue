<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StoreRequest;
use App\Jobs\DataHandlerJob;
use App\Jobs\GeneratorDashboardJob;

use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\AiChatTask;
use App\Models\Dashboard;
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

        $chats = AiChat::query()->where('company_id',$user->company->id)
            ->with('dashboards')->get();

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

        return DB::transaction(function () use ($request, $user) {

            $chat = AiChat::create([
                'user_id'    => $user->id,
                'company_id' => $user->company->id,
                'title'      => 'Генератция..',
            ]);

            $message = AiChatMessage::create([
                'chat_id' => $chat->id,
                'message' => $request->message,
            ]);

            $dashboard = Dashboard::query()->create(
                [
                    'chat_id' => $chat->id,
                    'company_id' => $user->company->id,
                    'name' => 'Генератция..',
                    'status' => 'generating',
                ]
            );

            if ($request->hasFile('data_file')) {

                $file = $request->file('data_file');

                $path = $user->company->id . '/chats/'.$chat->id.'/data';
                $name = uniqid('', true) . '.' . $file->getClientOriginalExtension();

                $storedPath = Storage::disk('company')->putFileAs(
                    $path,
                    $file,
                    $name
                );
                $fullPath = Storage::disk('company')->path($storedPath);
                $upload=UploadedFile::create([
                    'company_id'    => $user->company->id,
                    'chat_id'       => $chat->id,
                    'message_id'    => $message->id,
                    'original_name' => $file->getClientOriginalName(),
                    'file_path'     => $fullPath,
                    'file_type' => $file->getClientOriginalExtension(),
                    'file_size'     => $file->getSize(),
                ]);
            }
            $statuses = TaskStatus::query()
                ->pluck('id', 'name')
                ->toArray();
            $tasks = Task::query()
                ->pluck('id', 'name')
                ->toArray();


            Bus::chain([
                new DataHandlerJob($chat->id, $upload->id, $user->id,$message->id,$dashboard->id),
            ])->dispatch();


            $define_task = new \App\Helpers\Task\DefineTask([],$message->message);
            $result=$define_task->defineTask();

            $chat->title = $result['content']['task_title'];
            $chat->save();
            $dashboard->name=$result['content']['task_title'];
            $dashboard->status = "success";
            $dashboard->save();


            $message->tokens_used = $result['total_tokens'];
            $message->answer = $result['content']['message'];
            $message->status = 'answered';
            $message->save();

            return ['chat' => $chat,'message'=>$message,'result'=>$result];
        });
    }



}


