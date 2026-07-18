<?php

namespace App\Helpers\DataSource;

use App\Helpers\DataSource\Handlers\MySqlDataHandler;
use App\Helpers\DataSource\Handlers\TableDataHandler;
use App\Helpers\PythonRunner;
use App\Models\AiChat;
use App\Models\AiChatTask;
use App\Models\DataSourceType;
use App\Models\ExtractedData;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\UploadedFile;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class DataSourceRouter
{

    public $chat;
    public $uploadFile;

    public $message;
    public $user;

    public $storage;
    public $dick='company';

    public $chat_task;

    public $tasks;

    public $tasks_status;

    public $outputPath;
    public $dbFilePath;
    public $dataSourceType;
    /**
     * Create a new job instance.
     */
    public function __construct($chat_id,$upload_file_id,$user_id,$type_id=null)
    {

        $this->user= User::query()->with('company')->find($user_id);
        $this->chat=AiChat::query()->find($chat_id);
        $this->uploadFile=UploadedFile::query()->find($upload_file_id);
        $this->dataSourceType=DataSourceType::query()->find($type_id);
        $this->storage = storage_path('app/company/' . $this->user->company->id . '/chats/' . $this->chat->id);
        $this->outputPath = $this->storage . '/extracted_data';
        $this->dbFilePath = $this->outputPath . '/data.duckdb';

        $this->tasks_status = TaskStatus::query()
            ->pluck('id', 'name')
            ->toArray();
        $this->tasks = Task::query()
            ->pluck('id', 'name')
            ->toArray();

        $this->chat_task=AiChatTask::create([
            'chat_id'   => $this->chat->id,
            'task_id'   => $this->tasks['data_processing'],
            'status_id' => $this->tasks_status['start'],
        ]);

    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        try {

            $this->chat_task->status_id = $this->tasks_status['in_progress'];
            $this->chat_task->save();

            if ($this->uploadFile->file_type == 'sql') {
                if($this->dataSourceType->name == "mysql") {
                    $save_handler = new MySqlDataHandler(
                        $this->chat,
                        $this->uploadFile,
                        $this->storage
                    );
                }
            }
            else if($this->uploadFile->file_type == 'csv' || $this->uploadFile->file_type == 'xls' || $this->uploadFile->file_type == 'xlsx') {
                $save_handler = new TableDataHandler(
                    $this->chat,
                    $this->uploadFile,
                    $this->storage
                );
            }

            $resultExtract = $save_handler->end();

            $resultCreateDb = $this->createDuckdbDatabase($this->dbFilePath,$resultExtract['sql_path']);

            $lines = $resultCreateDb['output'] ?? [];
            $lastLine = trim((string) end($lines));

            if ($resultCreateDb['exit_code'] !== 0 || $lastLine !== 'ok') {
                throw new \Exception("Ошибка создания базы данных DuckDB. Ответ: " . json_encode($resultCreateDb));
            }
            $resultExtract = ExtractedData::create([
                'file_id'    => $this->uploadFile->id,
                'company_id' => $this->chat->company->id,
                'chat_id'    => $this->chat->id,
                'data_path'  => $this->dbFilePath,
                'document_type' => $this->uploadFile->file_type,
            ]);


             return $resultExtract;

        } catch (\Throwable $e) {

            $this->chat_task->status_id = $this->tasks_status['failed'];
            $this->chat_task->save();

            Log::error('DataSourceRouter error', [
                'chat_id' => $this->chat->id ?? null,
                'file_id' => $this->uploadFile->id ?? null,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function createDuckdbDatabase($dbFilePath,$sqlFilePath)
    {
        $path = "/home/azim/projects/Datavue/app/Helpers/DataSource/sql_to_duck.py";


        $runner = new PythonRunner(
            $path,
            [
                '--sql'  => $sqlFilePath,
                '--path' => $dbFilePath,
            ]
        );

        $result = $runner->run();

        return $result;
    }

}
