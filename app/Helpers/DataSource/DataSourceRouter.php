<?php

namespace App\Helpers\DataSource;

use App\Helpers\DataSource\Handlers\MySqlDataHandler;
use App\Helpers\DataSource\Handlers\SqliteDataHandler;
use App\Helpers\DataSource\Handlers\TableDataHandler;
use App\Models\DataSourceType;
use App\Models\DataSourceExtraction;
use App\Models\UploadedFile;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Разбирает загруженный файл и превращает его в готовый к запросам источник.
 *
 * Раньше класс работал «внутри чата»: путь хранения, имя импортируемой базы и
 * запись о ходе работ — всё строилось вокруг chat_id. С разделением источников
 * и чатов это стало неверным: файл загружают ДО того, как появится хоть один
 * чат, и один разобранный файл обслуживает потом сразу несколько чатов.
 * Поэтому здесь остались только компания и сам файл.
 */
class DataSourceRouter
{
    public UploadedFile $uploadFile;
    public User $user;
    public ?DataSourceType $dataSourceType;

    public int $companyId;

    public string $storage;
    public string $outputPath;
    public string $dbFilePath;
    public string $databaseName;

    private const SUPPORTED_TABLE_TYPES = ['csv', 'xls', 'xlsx'];

    /** Готовые базы SQLite: конвертировать нечего, файл уже является источником. */
    private const SUPPORTED_SQLITE_TYPES = ['db', 'sqlite', 'sqlite3'];

    /**
     * Тип источника, к которому привёл разбор файла.
     *
     * Контроллер раньше жёстко сохранял локальный источник как duckdb, из-за чего
     * загруженный .sqlite регистрировался чужим типом и не открывался.
     */
    public string $resolvedTypeName = 'duckdb';

    public function __construct($company_id, $upload_file_id, $user_id, $type_id = null)
    {
        $this->user = User::query()->with('company')->findOrFail($user_id);
        $this->uploadFile = UploadedFile::query()->findOrFail($upload_file_id);
        $this->dataSourceType = $type_id ? DataSourceType::query()->find($type_id) : null;
        $this->companyId = (int) $company_id;

        // Ключ хранения — id загруженного файла: он уже уникален и известен до
        // того, как источник получит собственный id.
        $this->storage = storage_path(
            'app/company/'.$this->companyId.'/sources/'.$this->uploadFile->id
        );
        $this->outputPath = $this->storage . '/extracted_data';
        $this->dbFilePath = in_array(
            strtolower((string) $this->uploadFile->file_type),
            self::SUPPORTED_SQLITE_TYPES,
            true
        )
            ? $this->outputPath . '/data.sqlite'
            : $this->outputPath . '/data.duckdb';

        if (!is_dir($this->outputPath)) {
            mkdir($this->outputPath, 0775, true);
        }

        $this->databaseName = 'data_source_' . $this->companyId . '_upload_' . $this->uploadFile->id;
    }

    /**
     * @return array{success: bool, message: string, extraction: ?DataSourceExtraction}
     */
    public function handle(): array
    {
        try {
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
                'company_id'    => $this->companyId,
                'data_path'     => $this->dbFilePath,
                'document_type' => $this->uploadFile->file_type,
            ]);

            return [
                'success'    => true,
                'message'    => $result['message'] ?? 'База данных успешно создана и файл импортирован.',
                'extraction' => $extraction,
                // null для table-хендлера, массив с host/port/... для mysql-дампа
                'connection' => $result['connection'] ?? null,
                'type_name'  => $this->resolvedTypeName,
            ];

        } catch (\Throwable $e) {

            Log::error('DataSourceRouter error', [
                'company_id' => $this->companyId,
                'file_id'    => $this->uploadFile->id ?? null,
                'error'      => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
                'trace'      => $e->getTraceAsString(),
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

        if (in_array($this->uploadFile->file_type, self::SUPPORTED_SQLITE_TYPES, true)) {
            $this->resolvedTypeName = 'sqlite';

            return new SqliteDataHandler(
                $this->uploadFile->file_path,
                $this->dbFilePath
            );
        }

        if (in_array($this->uploadFile->file_type, self::SUPPORTED_TABLE_TYPES, true)) {
            $this->resolvedTypeName = 'duckdb';

            return new TableDataHandler(
                $this->uploadFile->file_path,
                $this->outputPath,
                $this->dbFilePath
            );
        }

        return null;
    }
}
