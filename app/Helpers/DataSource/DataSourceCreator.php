<?php

namespace App\Helpers\DataSource;

use App\Helpers\DataSource\Handlers\GoogleSheetDataHandler;
use App\Models\DataSource;
use App\Models\DataSourceExtraction;
use App\Models\DataSourceType;
use App\Models\UploadedFile;
use App\Models\User;
use Illuminate\Http\UploadedFile as HttpUploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Подключение источника данных к компании.
 *
 * Логика жила прямо в ChatController::store, где источник создавался «попутно»,
 * вместе с чатом. Теперь подключение источника — самостоятельное действие
 * (компания сначала подключает базу, потом заводит на ней чаты), поэтому код
 * вынесен сюда и не знает ничего ни про чаты, ни про HTTP.
 */
class DataSourceCreator
{
    public function __construct(private User $user)
    {
    }

    /**
     * Загруженный файл: csv/xls/xlsx разбираются в DuckDB, .sqlite берётся как
     * есть, .sql-дамп импортируется в реальную MySQL-базу.
     *
     * @return array{success: bool, message: string, data_source: ?DataSource}
     */
    public function fromFile(
        HttpUploadedFile $file,
        ?int $typeId = null,
        ?string $version = null,
        ?string $name = null
    ): array {
        $companyId = $this->user->company_id;
        $storedFullPath = null;

        try {
            return DB::transaction(function () use ($file, $typeId, $version, $name, $companyId, &$storedFullPath) {

                $directory = $companyId . '/sources/data';
                $fileName = uniqid('', true) . '.' . $file->getClientOriginalExtension();

                $storedPath = Storage::disk('company')->putFileAs($directory, $file, $fileName);
                $storedFullPath = Storage::disk('company')->path($storedPath);

                $upload = UploadedFile::create([
                    'company_id'    => $companyId,
                    'original_name' => $file->getClientOriginalName(),
                    'file_path'     => $storedFullPath,
                    'file_type'     => strtolower($file->getClientOriginalExtension()),
                    'file_size'     => $file->getSize(),
                ]);

                $router = new DataSourceRouter(
                    $companyId,
                    $upload->id,
                    $this->user->id,
                    $typeId
                );

                $result = $router->handle();

                if (!$result['success']) {
                    throw new \RuntimeException($result['message']);
                }

                $extraction = $result['extraction'];
                $connection = $result['connection'] ?? null;
                $types = $this->types();
                $sourceName = $name ?: $upload->original_name;

                if ($connection) {
                    // .sql-дамп, импортированный в настоящую MySQL-базу, —
                    // сохраняем как remote-подключение, а не как файл.
                    $dataSource = DataSource::query()->create([
                        'company_id'      => $companyId,
                        'created_by'      => $this->user->id,
                        'type_id'         => $types[$connection['type_database']],
                        'extracted_id'    => $extraction->id,
                        'name'            => $sourceName,
                        'connection_type' => 'remote',
                        // Исходный формат — то, что подключал пользователь.
                        // В type_id уедет mysql, но в списке источников
                        // должно быть видно, что это был дамп.
                        'origin_format'   => strtolower($upload->file_type),
                        'version'         => $version,
                        'host'            => $connection['host'],
                        'port'            => $connection['port'],
                        'database'        => $connection['database'],
                        'username'        => $connection['username'],
                        'password'        => $connection['password'],
                        'path'            => null,
                    ]);
                } else {
                    // Тип берём тот, к которому привёл разбор файла: раньше здесь
                    // стояла единица (duckdb), и загруженный .sqlite сохранялся
                    // чужим типом — источник потом не открывался.
                    $localTypeName = $result['type_name'] ?? 'duckdb';

                    $dataSource = DataSource::query()->create([
                        'company_id'      => $companyId,
                        'created_by'      => $this->user->id,
                        'type_id'         => $types[$localTypeName] ?? $types['duckdb'],
                        'extracted_id'    => $extraction->id,
                        'name'            => $sourceName,
                        'connection_type' => 'local',
                        // csv/xlsx разбираются в DuckDB, но показывать
                        // пользователю нужно исходный формат файла.
                        'origin_format'   => strtolower($upload->file_type),
                        'version'         => $version,
                        'path'            => $extraction->data_path,
                    ]);
                }

                return [
                    'success'     => true,
                    'message'     => $result['message'],
                    'data_source' => $dataSource->load('type'),
                ];
            });

        } catch (\Throwable $e) {

            if ($storedFullPath && file_exists($storedFullPath)) {
                @unlink($storedFullPath);
            }

            return [
                'success'     => false,
                'message'     => $e->getMessage(),
                'data_source' => null,
            ];
        }
    }

    /**
     * Внешняя база: проверяем, что подключение реально работает, и только
     * после этого сохраняем — иначе в списке компании копились бы источники,
     * к которым невозможно обратиться.
     *
     * @return array{success: bool, message: string, data_source: ?DataSource}
     */
    public function fromRemote(array $data): array
    {
        $type = DataSourceType::query()->find($data['type_id']);

        $remoteDb = new ConnectRemoteDb(
            $data['host'],
            $data['port'],
            $data['database'],
            $type->name ?? null,
            $data['username'],
            $data['password'] ?? null
        );

        $check = $remoteDb->check();

        if (!$check['success']) {
            return [
                'success'     => false,
                'message'     => $check['message'],
                'data_source' => null,
            ];
        }

        $dataSource = DataSource::query()->create([
            'company_id'      => $this->user->company_id,
            'created_by'      => $this->user->id,
            'type_id'         => $data['type_id'],
            'name'            => $data['name'] ?: $data['database'],
            'host'            => $data['host'],
            'port'            => $data['port'],
            'database'        => $data['database'],
            'username'        => $data['username'],
            'password'        => $data['password'] ?? null,
            'connection_type' => 'remote',
            'version'         => $data['version'] ?: null,
        ]);

        return [
            'success'     => true,
            'message'     => $check['message'],
            'data_source' => $dataSource->load('type'),
        ];
    }

    /**
     * Google-таблица по ссылке.
     *
     * Выгружается один раз в аналитическую базу (DuckDB) — это снимок, а не
     * живая синхронизация. Дальше источник ничем не отличается от загруженного
     * файла, поэтому и хранится он как local.
     *
     * @return array{success: bool, message: string, data_source: ?DataSource}
     */
    public function fromGoogleSheet(string $url, ?string $name = null): array
    {
        $companyId = $this->user->company_id;

        try {
            // Ссылку проверяем до всякой работы: понятная ошибка про формат
            // ссылки полезнее, чем ошибка сети через минуту.
            GoogleSheetDataHandler::buildExportUrl($url);

            return DB::transaction(function () use ($url, $name, $companyId) {

                // Записи о загрузке нет — файла пользователь не присылал,
                // поэтому ключом хранения служит id самой записи источника.
                // Создаём её сразу, чтобы получить id, а путь дописываем ниже.
                $types = $this->types();

                $dataSource = DataSource::query()->create([
                    'company_id' => $companyId,
                    'created_by' => $this->user->id,
                    'type_id' => $types['google_sheets'] ?? $types['duckdb'],
                    'name' => $name ?: 'Google Таблица',
                    'connection_type' => 'local',
                    'origin_format' => 'google_sheets',
                    'options' => ['source_url' => $url],
                ]);

                $outputPath = storage_path(
                    'app/company/' . $companyId . '/sources/gsheet_' . $dataSource->id . '/extracted_data'
                );
                $dbFilePath = $outputPath . '/data.duckdb';

                $result = (new GoogleSheetDataHandler($url, $outputPath, $dbFilePath))->handle();

                if (!$result['success']) {
                    throw new \RuntimeException($result['message']);
                }

                $extraction = DataSourceExtraction::create([
                    'company_id' => $companyId,
                    'data_path' => $dbFilePath,
                    'document_type' => 'google_sheets',
                ]);

                // Запросы к данным идут через DuckDB-провайдер, поэтому
                // источник должен указывать на разобранный файл, а не на
                // тип google_sheets, для которого провайдера подключения нет.
                $dataSource->update([
                    'type_id' => $types['duckdb'],
                    'extracted_id' => $extraction->id,
                    'path' => $dbFilePath,
                ]);

                return [
                    'success' => true,
                    'message' => $result['message'],
                    'data_source' => $dataSource->fresh()->load('type'),
                ];
            });

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data_source' => null,
            ];
        }
    }

    /** @return array<string, int> */
    private function types(): array
    {
        return DataSourceType::query()->pluck('id', 'name')->toArray();
    }
}
