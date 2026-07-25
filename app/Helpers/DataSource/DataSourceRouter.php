<?php

namespace App\Helpers\DataSource;

use App\Helpers\DataSource\Handlers\MySqlDataHandler;
use App\Helpers\DataSource\Handlers\TableDataHandler;
use App\Models\AiChat;
use App\Models\AiChatTask;
use App\Models\DataSourceType;
use App\Models\DataSourceExtraction;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\UploadedFile;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class DataSourceRouter
{
    public AiChat $chat;
    public UploadedFile $uploadFile;
    public User $user;
    public ?DataSourceType $dataSourceType;

    public AiChatTask $chat_task;

    public array $tasks;
    public array $tasks_status;

    public string $storage;
    public string $outputPath;
    public string $dbFilePath;
    public string $databaseName;

    private const SUPPORTED_TABLE_TYPES = ['csv', 'xls', 'xlsx'];

    /**
     * Create a new job instance.
     */
    public function __construct($chat_id, $upload_file_id, $user_id, $type_id = null)
    {
        $this->user = User::query()->with('company')->findOrFail($user_id);
        $this->chat = AiChat::query()->findOrFail($chat_id);
        $this->uploadFile = UploadedFile::query()->findOrFail($upload_file_id);
        $this->dataSourceType = $type_id ? DataSourceType::query()->find($type_id) : null;

        $this->storage = storage_path('app/company/' . $this->user->company->id . '/chats/' . $this->chat->id);
        $this->outputPath = $this->storage . '/extracted_data';
        $this->dbFilePath = $this->outputPath . '/data.duckdb';

        if (!is_dir($this->outputPath)) {
            mkdir($this->outputPath, 0775, true);
        }

        $this->tasks_status = TaskStatus::query()->pluck('id', 'name')->toArray();
        $this->tasks = Task::query()->pluck('id', 'name')->toArray();

        $this->chat_task = AiChatTask::create([
            'chat_id'   => $this->chat->id,
            'task_id'   => $this->tasks['data_processing'],
            'status_id' => $this->tasks_status['start'],
        ]);

        $this->databaseName = 'data_source_' . $user_id . '_chat_' . $chat_id;
    }

    /**
     * Execute the job.
     *
     * @return array{success: bool, message: string, extraction: ?DataSourceExtraction}
     */
    public function handle(): array
    {
        try {
            $this->chat_task->status_id = $this->tasks_status['in_progress'];
            $this->chat_task->save();

            $dataHandler = $this->resolveHandler();

            if (!$dataHandler) {
                throw new \RuntimeException(
                    'Неподдерживаемый тип файла: ' . $this->uploadFile->file_type
                );
            }

            $result = $dataHandler->handle();

            if (!($result['success'] ?? false)) {
                throw new \RuntimeException(
                    $result['message'] ?? 'Ошибка при обработке файла'
                );
            }

            $extraction = DataSourceExtraction::create([
                'file_id'       => $this->uploadFile->id,
                'company_id'    => $this->chat->company_id,
                'chat_id'       => $this->chat->id,
                'data_path'     => $this->dbFilePath,
                'document_type' => $this->uploadFile->file_type,
            ]);

            $this->chat_task->status_id = $this->tasks_status['done'] ?? $this->tasks_status['in_progress'];
            $this->chat_task->save();

            return [
                'success'    => true,
                'message'    => $result['message'] ?? 'База данных успешно создана и файл импортирован.',
                'extraction' => $extraction,
                // null для table-хендлера, массив с host/port/... для mysql-дампа
                'connection' => $result['connection'] ?? null,
            ];

        } catch (\Throwable $e) {

            $this->chat_task->status_id = $this->tasks_status['failed'];
            $this->chat_task->save();

            Log::error('DataSourceRouter error', [
                'chat_id' => $this->chat->id ?? null,
                'file_id' => $this->uploadFile->id ?? null,
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return [
                'success'    => false,
                'message'    => 'Ошибка при обработке файла: ' . $e->getMessage(),
                'extraction' => null,
                'connection' => null,
            ];
        }
    }
    private function resolveHandler()
    {
        if ($this->uploadFile->file_type === 'sql') {
            if (!$this->dataSourceType || $this->dataSourceType->name !== 'mysql') {
                return null;
            }

            return new MySqlDataHandler(
                $this->uploadFile->file_path,
                $this->databaseName
            );
        }

        if (in_array($this->uploadFile->file_type, self::SUPPORTED_TABLE_TYPES, true)) {
            return new TableDataHandler(
                $this->uploadFile->file_path,
                $this->outputPath,
                $this->dbFilePath
            );
        }

        return null;
    }
}
