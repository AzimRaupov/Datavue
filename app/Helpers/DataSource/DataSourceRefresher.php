<?php

namespace App\Helpers\DataSource;

use App\Helpers\DataSource\Handlers\GoogleSheetDataHandler;
use App\Helpers\DataSource\Handlers\SqliteDataHandler;
use App\Helpers\DataSource\Handlers\TableDataHandler;
use App\Models\DataSource;
use App\Models\DataSourceTable;
use App\Models\UploadedFile;
use App\Models\User;
use Illuminate\Http\UploadedFile as HttpUploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DataSourceRefresher
{
    public function __construct(
        private DataSource $dataSource,
        private User $user
    ) {
    }

    public function requiresFile(): bool
    {
        return $this->dataSource->connection_type === 'local'
            && $this->dataSource->origin_format !== 'google_sheets';
    }

    public function handle(?HttpUploadedFile $file = null): array
    {
        try {
            if ($this->dataSource->connection_type === 'remote') {
                $result = $this->refreshRemote();
            } elseif ($this->dataSource->origin_format === 'google_sheets') {
                $result = $this->refreshGoogleSheet();
            } else {
                $result = $this->refreshFile($file);
            }

            if (!$result['success']) {
                return $result;
            }

            $changes = $this->detectSchemaChanges();

            $this->dataSource->forceFill([
                'refreshed_at' => now(),
                'grouping_status' => $changes['changed'] && $this->dataSource->grouping_status === 'completed'
                    ? 'stale'
                    : $this->dataSource->grouping_status,
            ])->save();

            return $result + [
                'schema_changed' => $changes['changed'],
                'added_tables' => $changes['added'],
                'removed_tables' => $changes['removed'],
            ];

        } catch (\Throwable $e) {
            Log::error('DataSourceRefresher: обновление не удалось', [
                'data_source_id' => $this->dataSource->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Не удалось обновить данные: ' . $e->getMessage(),
            ];
        }
    }

    private function refreshRemote(): array
    {
        $router = new ConnectionProviderRouter($this->dataSource->id);

        $check = $router->check();

        $ok = is_array($check) ? ($check['success'] ?? false) : (bool) $check;

        if (!$ok) {
            return [
                'success' => false,
                'message' => is_array($check)
                    ? ($check['message'] ?? 'Не удалось подключиться к базе.')
                    : 'Не удалось подключиться к базе.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Подключение работает, схема перечитана.',
        ];
    }

    private function detectSchemaChanges(): array
    {
        $known = DataSourceTable::query()
            ->where('data_source_id', $this->dataSource->id)
            ->pluck('name')
            ->map(fn ($n) => (string) $n)
            ->all();

        if (empty($known)) {
            return ['changed' => false, 'added' => [], 'removed' => []];
        }

        try {
            $current = (new ConnectionProviderRouter($this->dataSource->id))->showTables();
        } catch (\Throwable $e) {
            Log::warning('DataSourceRefresher: не удалось перечитать список таблиц', [
                'data_source_id' => $this->dataSource->id,
                'error' => $e->getMessage(),
            ]);

            return ['changed' => false, 'added' => [], 'removed' => []];
        }

        $current = array_map('strval', $current);

        $added = array_values(array_diff($current, $known));
        $removed = array_values(array_diff($known, $current));

        return [
            'changed' => $added !== [] || $removed !== [],
            'added' => $added,
            'removed' => $removed,
        ];
    }

    private function refreshGoogleSheet(): array
    {
        $url = $this->dataSource->options['source_url'] ?? null;

        if (!$url) {
            return [
                'success' => false,
                'message' => 'У источника не сохранена ссылка на таблицу — обновить нельзя.',
            ];
        }

        $dbFilePath = $this->dataSource->path;
        $outputPath = dirname($dbFilePath);

        $result = (new GoogleSheetDataHandler($url, $outputPath, $dbFilePath))->handle();

        return [
            'success' => $result['success'],
            'message' => $result['success']
                ? 'Google-таблица перечитана, данные обновлены.'
                : $result['message'],
        ];
    }

    private function refreshFile(?HttpUploadedFile $file): array
    {
        if (!$file) {
            return ['success' => false, 'message' => 'Файл не был передан.'];
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension !== $this->dataSource->origin_format) {
            return [
                'success' => false,
                'message' => sprintf(
                    'Ожидается файл .%s — тот же формат, что при подключении. Иначе сломаются готовые дашборды.',
                    $this->dataSource->origin_format
                ),
            ];
        }

        $companyId = $this->dataSource->company_id;
        $directory = $companyId . '/sources/data';
        $fileName = uniqid('', true) . '.' . $extension;

        $storedPath = Storage::disk('company')->putFileAs($directory, $file, $fileName);
        $storedFullPath = Storage::disk('company')->path($storedPath);

        $upload = UploadedFile::create([
            'company_id' => $companyId,
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $storedFullPath,
            'file_type' => $extension,
            'file_size' => $file->getSize(),
        ]);

        $dbFilePath = $this->dataSource->path;
        $outputPath = dirname($dbFilePath);

        $handler = in_array($extension, ['db', 'sqlite', 'sqlite3'], true)
            ? new SqliteDataHandler($storedFullPath, $dbFilePath)
            : new TableDataHandler($storedFullPath, $outputPath, $dbFilePath);

        $result = $handler->handle();

        if (!($result['success'] ?? false)) {

            @unlink($storedFullPath);

            return [
                'success' => false,
                'message' => $result['message'] ?? 'Не удалось разобрать файл.',
            ];
        }

        $this->dataSource->extracted?->update(['file_id' => $upload->id]);

        return [
            'success' => true,
            'message' => 'Данные обновлены из нового файла.',
        ];
    }
}
