<?php

namespace App\Jobs;

use App\Helpers\DataHandlers\SqlDataHandler;
use App\Models\AiChat;
use App\Models\AiChatTask;
use App\Models\ExtractedData;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\UploadedFile;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DataHandlerJob implements ShouldQueue
{
    use Queueable;

    public $chat;
    public $uploadFile;

    public $message;
    public $user;

    public $storage;
    public $dick='company';

    public $chat_task;

    public $tasks;

    public $tasks_status;

    /**
     * Create a new job instance.
     */
    public function __construct($chat_id,$upload_file_id,$user_id,$chat_task_id)
    {
        $user= User::query()->with('company')->find($user_id);
        $this->chat=AiChat::query()->find($chat_id);
        $this->uploadFile=UploadedFile::query()->find($upload_file_id);
        $this->chat_task=AiChatTask::query()->find($chat_task_id);
        $this->storage = storage_path('app/company/' . $user->company->id . '/chats/' . $this->chat->id);
        $this->tasks = Task::query()
            ->pluck('id', 'name')
            ->toArray();
        $this->tasks_status = TaskStatus::query()
            ->pluck('id', 'name')
            ->toArray();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {

            if ($this->uploadFile->file_type == 'sql') {
                $save_handler = new SqlDataHandler(
                    $this->chat,
                    $this->uploadFile,
                    $this->storage
                );
            }

            $resultExtract = $save_handler->end();

            ExtractedData::create([
                'file_id'    => $this->uploadFile->id,
                'company_id' => $this->chat->company->id,
                'chat_id'    => $this->chat->id,
                'data_path'  => $resultExtract['data_path'],
            ]);

            $this->chat_task->status_id = $this->tasks_status['completed'];
            $this->chat_task->save();

        } catch (\Throwable $e) {

            $this->chat_task->status_id = $this->tasks_status['failed'];
            $this->chat_task->save();

            throw $e;
        }
    }
}
