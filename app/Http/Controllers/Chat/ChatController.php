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
        return DB::transaction(function () use ($request, $user) {

            $chat = AiChat::create([
                'user_id'    => $user->id,
                'company_id' => $user->company->id,
            ]);
            $type = DataSourceType::query()->find($request->input('type_id'));

           if($request->input('connection_type')=="local") {

               if ($request->hasFile('data_file')) {
                   $file = $request->file('data_file');
                   $path = $user->company->id . '/chats/' . $chat->id . '/data';
                   $name = uniqid('', true) . '.' . $file->getClientOriginalExtension();
                   $storedPath = Storage::disk('company')->putFileAs(
                       $path,
                       $file,
                       $name
                   );
                   $fullPath = Storage::disk('company')->path($storedPath);
                   $upload = UploadedFile::create([
                       'company_id' => $user->company->id,
                       'chat_id' => $chat->id,
                       'original_name' => $file->getClientOriginalName(),
                       'file_path' => $fullPath,
                       'file_type' => $file->getClientOriginalExtension(),
                       'file_size' => $file->getSize(),
                   ]);
               }


                $data_source=new DataSourceRouter($chat->id, $upload->id, $user->id,$request->input('type_id'));
                $resultHandler=$data_source->handle();

                $dataSource = DataSource::query()->create([
                   'company_id'=>$chat->company_id,
                   'chat_id' => $chat->id,
                   'type_id' => 1,
                   'extracted_id'=>$resultHandler->id,
                   'name'=>'test',
                   'connection_type'=>$request->input('connection_type'),
                   'version'=>$request->input('version'),
                   'path'=>$resultHandler->data_path
                ]);



               return ['chat' => $chat,'success'=>true,'message'=>'Успешно'];


           }
           if($request->input('connection_type')=="remote") {
               $remoteDb =new ConnectRemoteDb(
                   $request->host,
                   $request->port,
                   $request->database,
                   $type->name,
                   $request->username,
                   $request->password

               );
               $resultCheck = $remoteDb->check();
               if($resultCheck['success']) {
                   $dataSource = DataSource::query()->create([
                       'company_id'=>$chat->company_id,
                       'chat_id' => $chat->id,
                       'type_id' => $request->input('type_id'),
                       'name'=>'test',
                       'host'=>$request->input('host'),
                       'port'=>$request->input('port'),
                       'database'=>$request->input('database'),
                       'username'=>$request->input('username'),
                       'password'=>$request->input('password'),
                       'connection_type'=>$request->input('connection_type'),
                       'version' => $request->input('version') ?: null,
                       ]);
                   return ['chat' => $chat,'success'=>true,'message'=>$resultCheck['message']];
               }
               else{
                   return ['chat' => $chat,'success'=>false,'message'=>$resultCheck['message']];

               }

           }
            return ['chat' => $chat];
        });
    }



}


