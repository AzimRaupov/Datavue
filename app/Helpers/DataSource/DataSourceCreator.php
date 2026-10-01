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

class DataSourceCreator
{
    public function __construct(private User $user)
    {
    }

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

                    $dataSource = DataSource::query()->create([
                        'company_id'      => $companyId,
                        'created_by'      => $this->user->id,
                        'type_id'         => $types[$connection['type_database']],
                        'extracted_id'    => $extraction->id,
                        'name'            => $sourceName,
                        'connection_type' => 'remote',

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

                    $localTypeName = $result['type_name'] ?? 'duckdb';

                    $dataSource = DataSource::query()->create([
                        'company_id'      => $companyId,
                        'created_by'      => $this->user->id,
                        'type_id'         => $types[$localTypeName] ?? $types['duckdb'],
                        'extracted_id'    => $extraction->id,
                        'name'            => $sourceName,
                        'connection_type' => 'local',

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

    public function fromGoogleSheet(string $url, ?string $name = null): array
    {
        $companyId = $this->user->company_id;

        try {

            GoogleSheetDataHandler::buildExportUrl($url);

            return DB::transaction(function () use ($url, $name, $companyId) {

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

    private function types(): array
    {
        return DataSourceType::query()->pluck('id', 'name')->toArray();
    }
}
