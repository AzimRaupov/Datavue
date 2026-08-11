<?php

namespace App\Helpers\Export;

use App\Helpers\Ai\DashboardAi;
use App\Helpers\Ai\ExportAi;
use App\Helpers\Chat\ChatContext;
use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\DataSource\DataSourceGrouping;
use App\Helpers\DataSource\SchemaOptions;
use App\Helpers\PythonRunner;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\ChatExport;
use App\Models\DataSourceGroup;
use App\Models\DataSourceTable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Выгружает результат запроса пользователя в файл.
 *
 * Полный путь одной просьбы «посчитай топ-10 клиентов и сохрани в csv»:
 *
 *   1. Модель раскладывает просьбу на задачу, формат, заголовок и имя файла.
 *   2. По смысловым группам источника отбираются нужные таблицы и их схема.
 *   3. Модель пишет main(), который получает данные и вызывает save_result().
 *   4. Скрипт выполняется; при падении ошибка возвращается модели на починку.
 *   5. Файл регистрируется в chat_exports и попадает в чат ссылкой.
 *
 * Почему через Python, а не через PHP: тот же путь уже используется для
 * виджетов, а pandas + openpyxl/reportlab/python-docx дают четыре формата
 * одним рантаймом. Сам файл при этом пишет платформа, а не модель —
 * см. ExportCodeTemplater.
 */
class ChatExportGenerator
{
    /** Сколько таблиц максимум уходит в промпт вместе со схемой. */
    private const MAX_TABLES = 25;

    public ?AiChat $chat = null;

    public ?AiChatMessage $message = null;

    public $dataSource;

    private ConnectionProviderRouter $router;

    private ExportAi $exportAi;

    private int $totalTokens = 0;

    /** Пояснение модели, если задачу не удалось выполнить дословно. */
    private string $modelNote = '';

    public function __construct(
        private int $chatId,
        private int $messageId,
        private string $instruction
    ) {
        $this->chat = AiChat::query()->find($chatId);
        $this->message = AiChatMessage::query()->find($messageId);

        if (!$this->chat || !$this->message) {
            throw new RuntimeException("Чат #{$chatId} или сообщение #{$messageId} не найдены");
        }

        $this->dataSource = $this->chat->resolveDataSource(['type', 'extracted']);

        if (!$this->dataSource) {
            throw new RuntimeException("К чату #{$chatId} не подключён источник данных");
        }

        $this->router = new ConnectionProviderRouter($this->dataSource->id);
        $this->exportAi = new ExportAi($this->dataSource);
    }

    public function totalTokens(): int
    {
        return $this->totalTokens;
    }

    /**
     * @return array{export: ChatExport, answer: string, total_tokens: int}
     */
    public function handle(): array
    {
        $spec = $this->defineSpec();

        $tables = $this->selectTables($spec['instruction']);

        if (empty($tables)) {
            throw new RuntimeException(
                'Не удалось определить, из каких таблиц брать данные для выгрузки.'
            );
        }

        $schema = $this->router->getSchema($tables, SchemaOptions::detailed());

        [$path, $fileName] = $this->prepareTarget($spec);

        $templater = new ExportCodeTemplater(
            $this->dataSource,
            $spec['format'],
            $path,
            $spec['title']
        );

        $result = $this->buildFile($templater, $spec, $schema, $path);

        $export = $this->register($spec, $fileName, $path, $result['meta'], $result['code']);

        return [
            'export' => $export,
            'answer' => $this->composeAnswer($export, $result['meta']),
            'total_tokens' => $this->totalTokens,
        ];
    }

    /**
     * Что выгружаем, в каком формате и как назовём файл.
     *
     * @return array{format: string, instruction: string, title: string, file_name: string}
     */
    private function defineSpec(): array
    {
        $context = new ChatContext($this->chatId);

        $history = AiChatMessage::query()
            ->where('chat_id', $this->chatId)
            ->where('id', '!=', $this->messageId)
            ->orderByDesc('id')
            ->limit(6)
            ->get(['message', 'answer']);

        $spec = [];

        try {
            $response = $this->exportAi->defineSpec([
                'message' => $this->message->message,
                'history' => json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                'context' => $context->toJson(),
            ]);

            $this->totalTokens += (int) ($response['total_tokens'] ?? 0);

            $spec = is_array($response['content'] ?? null) ? $response['content'] : [];
        } catch (Throwable $e) {
            // Шаг вспомогательный: без него остаётся исходная формулировка
            // пользователя и формат, распознанный по его же словам.
            Log::warning('ChatExportGenerator: spec detection failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // Слова пользователя важнее ответа модели: если он написал «в pdf»,
        // а модель предложила csv — прав пользователь.
        $format = ExportFormat::detect($this->message->message)
            ?? ExportFormat::detect($this->instruction)
            ?? ExportFormat::normalize($spec['format'] ?? null);

        $instruction = trim((string) ($spec['instruction'] ?? ''));

        if ($instruction === '') {
            $instruction = trim($this->instruction) ?: $this->message->message;
        }

        $title = trim((string) ($spec['title'] ?? ''));

        if ($title === '') {
            $title = Str::limit(trim($this->message->message), 60, '');
        }

        $resolved = [
            'format' => $format,
            'instruction' => $instruction,
            'title' => $title,
            'file_name' => $this->fileNameBase($spec['file_name'] ?? null, $title),
        ];

        Log::info('ChatExportGenerator: spec', $resolved);

        return $resolved;
    }

    /**
     * Имя файла без расширения: латиница, дефисы, дата — чтобы выгрузки
     * одного и того же отчёта в папке пользователя не сливались в одну.
     */
    private function fileNameBase(?string $suggested, string $title): string
    {
        $base = Str::slug((string) $suggested);

        if ($base === '') {
            $base = Str::slug($title);
        }

        if ($base === '') {
            $base = 'export';
        }

        return Str::limit($base, 40, '').'-'.now()->format('Y-m-d');
    }

    /**
     * Таблицы, из которых берутся данные.
     *
     * Тот же приём, что и в генераторе дашборда: сначала модель выбирает
     * смысловые группы, и только их таблицы попадают в промпт вместе со
     * схемой. На источнике в сотни таблиц полная схема не поместилась бы
     * ни в контекст модели, ни в разумное время ответа.
     *
     * @return array<int, string>
     */
    private function selectTables(string $instruction): array
    {
        $this->ensureGrouped();

        $groups = DataSourceGroup::query()
            ->where('data_source_id', $this->dataSource->id)
            ->get(['id', 'name', 'description']);

        if ($groups->isEmpty()) {
            // Группировки нет — работаем по списку таблиц источника напрямую.
            return array_slice($this->router->showTables(), 0, self::MAX_TABLES);
        }

        try {
            $response = (new DashboardAi($this->dataSource))->defineGroups(
                groups: $groups,
                text: $instruction
            );

            $this->totalTokens += (int) ($response['total_tokens'] ?? 0);

            $groupIds = $response['content']['groups'] ?? [];
        } catch (Throwable $e) {
            Log::warning('ChatExportGenerator: group selection failed', [
                'error' => $e->getMessage(),
            ]);

            $groupIds = [];
        }

        $tables = DataSourceTable::query()
            ->when(
                is_array($groupIds) && $groupIds,
                fn ($query) => $query->whereIn('data_source_group_id', $groupIds),
                fn ($query) => $query->whereIn('data_source_group_id', $groups->pluck('id'))
            )
            ->limit(self::MAX_TABLES)
            ->pluck('name')
            ->all();

        Log::info('ChatExportGenerator: tables selected', [
            'groups' => $groupIds,
            'tables' => $tables,
        ]);

        return $tables;
    }

    /**
     * Группировка таблиц — разовая операция на источник, но выгрузка может
     * оказаться первым, о чём пользователь попросил в новом чате.
     */
    private function ensureGrouped(): void
    {
        try {
            $grouping = new DataSourceGrouping($this->dataSource->id);

            if ($grouping->load()) {
                return;
            }

            $grouping->handle();
            $grouping->save();
        } catch (Throwable $e) {
            Log::warning('ChatExportGenerator: grouping failed', [
                'data_source_id' => $this->dataSource->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Куда положим файл.
     *
     * Каталог с токеном в имени: два экспорта одного отчёта не затирают
     * друг друга, а по ссылке нельзя добраться до чужого файла подбором пути.
     *
     * @return array{0: string, 1: string}
     */
    private function prepareTarget(array $spec): array
    {
        $fileName = $spec['file_name'].'.'.ExportFormat::extension($spec['format']);

        $directory = storage_path(
            'app/company/'.$this->chat->company_id.
            '/chats/'.$this->chat->id.
            '/exports/'.$this->messageId.'-'.Str::random(8)
        );

        File::ensureDirectoryExists($directory);

        return [$directory.'/'.$fileName, $fileName];
    }

    /**
     * Генерация кода, запуск и починка при ошибке.
     *
     * @return array{meta: array, code: string}
     */
    private function buildFile(
        ExportCodeTemplater $templater,
        array $spec,
        array $schema,
        string $path
    ): array {
        $schemaJson = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $generated = $this->exportAi->generateCode([
            'instruction' => $spec['instruction'],
            'tables_scheme' => $schemaJson,
            'runtime' => $templater->runtimeSummary(),
            'format' => $spec['format'],
            'format_label' => ExportFormat::label($spec['format']),
            'format_rules' => ExportFormat::rules($spec['format']),
            'row_limit' => ExportFormat::rowLimit($spec['format']),
            'title' => $spec['title'],
        ]);

        $this->totalTokens += $generated['total_tokens'];

        $mainBody = $generated['code'];
        $attempts = max(1, (int) config('exports.max_attempts', 3));
        $lastError = '';
        $lastOutput = '';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $code = $templater->assemble($mainBody);

            $run = (new PythonRunner(timeoutSeconds: (int) config('exports.timeout', 180)))
                ->runCode($code);

            $output = implode("\n", $run['output'] ?? []);
            $meta = $this->extractMeta($run['output'] ?? []);

            if ($meta !== null && is_file($path) && filesize($path) > 0) {
                // Скрипт мог записать файл не туда: путь задаём мы, но
                // проверить дешевле, чем потом отдавать пользователю 404.
                if (($meta['file'] ?? null) !== $path) {
                    Log::warning('ChatExportGenerator: unexpected output path', [
                        'expected' => $path,
                        'reported' => $meta['file'] ?? null,
                    ]);
                }

                $this->storeCode($path, $code);

                return ['meta' => $meta, 'code' => $code];
            }

            $lastOutput = $output;
            $lastError = $this->errorFromRun($run, $meta, $path);

            Log::warning('ChatExportGenerator: run failed', [
                'attempt' => $attempt,
                'exit_code' => $run['exit_code'] ?? null,
                'error' => Str::limit($lastError, 500),
            ]);

            if ($attempt === $attempts) {
                break;
            }

            $fixed = $this->exportAi->fixCode([
                'code' => $code,
                'errors' => $lastError,
                'output' => Str::limit($output, 4000),
                'instruction' => $spec['instruction'],
                'tables_scheme' => $schemaJson,
                'format_label' => ExportFormat::label($spec['format']),
                'title' => $spec['title'],
            ]);

            $this->totalTokens += $fixed['total_tokens'];

            if ($fixed['code'] === '') {
                break;
            }

            $mainBody = $fixed['code'];

            if ($fixed['message'] !== '') {
                $this->modelNote = $fixed['message'];
            }
        }

        throw new RuntimeException(
            'Не удалось сформировать файл: '.Str::limit($lastError ?: $lastOutput, 400)
        );
    }

    /**
     * Отчёт save_result() из stdout.
     *
     * Ищем с конца: pandas и драйверы любят писать предупреждения,
     * и нужная строка почти всегда последняя.
     */
    private function extractMeta(array $output): ?array
    {
        foreach (array_reverse($output) as $line) {
            $line = trim($line);

            if ($line === '' || !str_starts_with($line, '{')) {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded) && ($decoded['ok'] ?? false)) {
                return $decoded;
            }
        }

        return null;
    }

    private function errorFromRun(array $run, ?array $meta, string $path): string
    {
        $output = implode("\n", $run['output'] ?? []);

        if ($meta === null) {
            $traceback = $this->extractTraceback($run['output'] ?? []);

            return $traceback !== '' ? $traceback : Str::limit($output, 2000);
        }

        if (!is_file($path)) {
            return 'save_result() отработал, но файл по пути '.$path.' не появился.';
        }

        return 'Файл создан пустым (0 байт) — данных в выгрузке не оказалось.';
    }

    /**
     * Питоновский traceback из вывода: модели нужна ошибка, а не сто строк
     * предупреждений pandas перед ней.
     */
    private function extractTraceback(array $output): string
    {
        $start = null;

        foreach ($output as $index => $line) {
            if (str_contains($line, 'Traceback (most recent call last)')) {
                $start = $index;
            }
        }

        if ($start === null) {
            return '';
        }

        return implode("\n", array_slice($output, $start));
    }

    /**
     * Код кладём рядом с файлом: когда выгрузка окажется неверной,
     * первым делом смотрят именно на него.
     */
    private function storeCode(string $path, string $code): void
    {
        try {
            File::put(dirname($path).'/generated_script.py', $code);
        } catch (Throwable $e) {
            Log::warning('ChatExportGenerator: failed to store script', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function register(
        array $spec,
        string $fileName,
        string $path,
        array $meta,
        string $code
    ): ChatExport {
        $ttl = (int) config('exports.ttl_days', 30);

        return ChatExport::query()->create([
            'company_id' => $this->chat->company_id,
            'chat_id' => $this->chat->id,
            'message_id' => $this->messageId,
            'token' => ChatExport::newToken(),
            'format' => $spec['format'],
            'title' => $meta['title'] ?: $spec['title'],
            'file_name' => $fileName,
            'path' => $path,
            'size' => (int) (filesize($path) ?: 0),
            'rows_count' => (int) ($meta['rows'] ?? 0),
            'total_rows' => (int) ($meta['total_rows'] ?? $meta['rows'] ?? 0),
            'truncated' => (bool) ($meta['truncated'] ?? false),
            'columns' => $meta['columns'] ?? [],
            'code' => $code,
            'status' => 'ready',
            'expires_at' => $ttl > 0 ? now()->addDays($ttl) : null,
        ]);
    }

    /**
     * Ответ в чат: предпросмотр содержимого и ссылка на файл.
     *
     * Предпросмотр собирается из данных, которые вернул сам скрипт, — второе
     * обращение к модели здесь ничего не добавило бы, кроме расхода токенов
     * и риска, что она перепишет числа по-своему.
     */
    private function composeAnswer(ChatExport $export, array $meta): string
    {
        $lines = [];

        if ($export->title) {
            $lines[] = '### '.$export->title;
            $lines[] = '';
        }

        $lines[] = sprintf(
            'Готово: **%s** — %s, %s.',
            $this->plural((int) $export->rows_count, 'строка', 'строки', 'строк'),
            $export->format_label,
            $export->size_human
        );
        $lines[] = '';

        $table = $this->previewTable($meta);

        if ($table !== '') {
            $lines[] = $table;
            $lines[] = '';

            $previewRows = count($meta['preview'] ?? []);

            if ($previewRows > 0 && $previewRows < (int) $export->rows_count) {
                $lines[] = sprintf(
                    '_Показаны первые %d из %d строк — полный набор в файле._',
                    $previewRows,
                    (int) $export->rows_count
                );
                $lines[] = '';
            }
        }

        if ($export->truncated) {
            $lines[] = sprintf(
                '_Результат обрезан до %d строк из %d — это потолок для формата %s._',
                (int) $export->rows_count,
                (int) $export->total_rows,
                $export->format_label
            );
            $lines[] = '';
        }

        if ($this->modelNote !== '') {
            $lines[] = '_'.$this->modelNote.'_';
            $lines[] = '';
        }

        $lines[] = $export->markdownLink();

        return implode("\n", $lines);
    }

    private function previewTable(array $meta): string
    {
        $columns = $meta['columns'] ?? [];
        $preview = $meta['preview'] ?? [];

        if (!is_array($columns) || !$columns || !is_array($preview) || !$preview) {
            return '';
        }

        // Широкую таблицу в узком чате читать невозможно — показываем начало.
        $limit = 6;
        $cut = count($columns) > $limit;
        $columns = array_slice($columns, 0, $limit);

        $header = '| '.implode(' | ', array_map([$this, 'cell'], $columns)).($cut ? ' | … |' : ' |');
        $divider = '|'.str_repeat(' --- |', count($columns) + ($cut ? 1 : 0));

        $rows = [];

        foreach ($preview as $row) {
            if (!is_array($row)) {
                continue;
            }

            $values = array_map([$this, 'cell'], array_slice($row, 0, $limit));

            $rows[] = '| '.implode(' | ', $values).($cut ? ' | … |' : ' |');
        }

        return implode("\n", array_merge([$header, $divider], $rows));
    }

    /**
     * Значение ячейки внутри markdown-таблицы: вертикальная черта и перенос
     * строки развалили бы разметку.
     */
    private function cell($value): string
    {
        $value = str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], (string) $value);

        return Str::limit(trim($value), 40);
    }

    private function plural(int $count, string $one, string $few, string $many): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        $form = match (true) {
            $mod10 === 1 && $mod100 !== 11 => $one,
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => $few,
            default => $many,
        };

        return number_format($count, 0, '.', ' ').' '.$form;
    }
}
