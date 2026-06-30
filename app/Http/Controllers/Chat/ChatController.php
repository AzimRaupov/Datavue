<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StoreRequest;
use App\Jobs\DataHandlerJob;
use App\Jobs\GeneratorDashboardJob;

use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\AiChatTask;
use App\Models\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;

class ChatController extends Controller
{
    public function index(Request $request){

        $user = auth()->user();
        return $user;
    }

    public function show($id){

        $user = auth()->user();

        $chat = AiChat::query()->where('id', $id)
            ->where('company_id',$user->company->id)->first();

        return $chat;

    }

    public function store(StoreRequest $request)
    {
        $user = auth()->user();

        return DB::transaction(function () use ($request, $user) {

            $chat = AiChat::create([
                'user_id'    => $user->id,
                'company_id' => $user->company->id,
                'title'      => 'Open',
            ]);

            $message = AiChatMessage::create([
                'chat_id' => $chat->id,
                'message' => $request->message,
            ]);

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

            $task=AiChatTask::create([
                'chat_id'   => $chat->id,
                'task_id'   => 3,
                'status_id' => 7,
            ]);

            Bus::chain([
                new DataHandlerJob($chat->id, $upload->id, $user->id, $task->id),
                new GeneratorDashboardJob($message->id,$chat->id,$user->id),
            ])->dispatch();
            return ['chat' => $chat,'message'=>$message];
        });
    }



}


