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

/**
 * Обновление данных уже подключённого источника.
 *
 * Раньше файловые источники и Google-таблицы были снимком навсегда: чтобы
 * подтянуть свежие данные, приходилось заводить новый источник — и терять
 * вместе со старым все построенные на нём дашборды и чаты.
 *
 * Ключевое решение: разобранный файл ПЕРЕЗАПИСЫВАЕТСЯ ПО ТОМУ ЖЕ ПУТИ.
 * Сгенерированный Python-код виджетов обращается к базе по пути из
 * data_sources.path, поэтому при неизменном пути все дашборды продолжают
 * работать без единой правки — меняются только цифры.
 */
class DataSourceRefresher
{
    public function __construct(
        private DataSource $dataSource,
        private User $user
    ) {
    }

    /**
     * Нужен ли файл для обновления.
     *
     * Загруженный файл заменяется новой версией; Google-таблица и внешняя база
     * обновляются сами — по сохранённой ссылке и по живому подключению.
     */
    public function requiresFile(): bool
    {
        return $this->dataSource->connection_type === 'local'
            && $this->dataSource->origin_format !== 'google_sheets';
    }

    /**
     * @param HttpUploadedFile|null $file Новый файл — только для загруженных файлов.
     *
     * @return array{success: bool, message: string, schema_changed?: bool, added_tables?: array, removed_tables?: array}
     */
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

            // Состав таблиц мог измениться — и у файла, и у внешней базы.
            // Группировка от этого не ломается, но перестаёт быть точной:
            // новые таблицы агенту не видны, удалённые он всё ещё предлагает.
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

    /**
     * Внешняя база: сами данные там всегда свежие, обновлять нечего.
     *
     * Устаревает другое — СНИМОК СХЕМЫ. Список таблиц и их разбивка по группам
     * сохранены в момент подключения, и если в базе с тех пор появились новые
     * таблицы, агент про них попросту не знает. Поэтому «обновить» здесь
     * означает: проверить подключение и перечитать состав схемы.
     */
    private function refreshRemote(): array
    {
        $router = new ConnectionProviderRouter($this->dataSource->id);

        $check = $router->check();

        // Провайдеры возвращают либо массив с success, либо простой флаг —
        // приводим к одному виду.
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

    /**
     * Сравнивает текущий состав таблиц с тем, что был сохранён при последней
     * группировке.
     *
     * @return array{changed: bool, added: array<int, string>, removed: array<int, string>}
     */
    private function detectSchemaChanges(): array
    {
        $known = DataSourceTable::query()
            ->where('data_source_id', $this->dataSource->id)
            ->pluck('name')
            ->map(fn ($n) => (string) $n)
            ->all();

        // Группировки ещё не было — сравнивать не с чем.
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

    /**
     * Google-таблица: ссылка уже сохранена при подключении, новый ввод не нужен.
     */
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

    /**
     * Файл: пользователь присылает новую версию того же набора данных.
     *
     * Расширение обязано совпадать с исходным — csv вместо xlsx поменяет и
     * способ разбора, и, скорее всего, состав колонок, а на старых колонках
     * уже построены виджеты.
     */
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
            // Разбор не удался — исходник не оставляем на диске.
            @unlink($storedFullPath);

            return [
                'success' => false,
                'message' => $result['message'] ?? 'Не удалось разобрать файл.',
            ];
        }

        // Имя источника не трогаем: его мог задать пользователь. Обновляем
        // только связь с последним загруженным файлом.
        $this->dataSource->extracted?->update(['file_id' => $upload->id]);

        return [
            'success' => true,
            'message' => 'Данные обновлены из нового файла.',
        ];
    }
}
