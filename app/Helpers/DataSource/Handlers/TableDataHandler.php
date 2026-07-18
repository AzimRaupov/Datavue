<?php

namespace App\Helpers\DataSource\Handlers;

use App\Helpers\PythonRunner;
use App\Models\AiChatMessage;
use App\Models\ExtractedData;
use App\Models\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

class TableDataHandler
{
    public $pathData;
    public $chat;
    public $uploadFile;
    public $outputDisk = 'company';

    public $outputPath;
    public $sqlFilePath;
    public $isSuccess = false;
    public $errorMessage;
    public $stats = [];
    public $storage;

    private array $allowedExtensions = ['csv', 'xlsx', 'xls'];

    public function __construct($chat, $uploadFile, $storage)
    {
        ini_set('memory_limit', '2G');
        set_time_limit(300);

        try {
            $this->chat = $chat;
            $this->uploadFile = $uploadFile;
            $this->storage = $storage;

            $this->pathData = $this->uploadFile->file_path;
            $this->outputPath = $storage . '/extracted_data';

            Log::info('ExcelCsvDataHandler: начало обработки', [
                'chat_id' => $chat->id,
                'upload_id' => $uploadFile->id,
                'source' => $this->pathData,
                'output_path' => $this->outputPath,
            ]);

            $this->process();

        } catch (\Throwable $e) {
            $this->isSuccess = false;
            $this->errorMessage = $e->getMessage();

            Log::error('ExcelCsvDataHandler: ошибка инициализации', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function process(): void
    {
        if (!file_exists($this->pathData)) {
            throw new \RuntimeException("Исходный файл не найден: {$this->pathData}");
        }

        if (!is_readable($this->pathData)) {
            throw new \RuntimeException("Файл не доступен для чтения: {$this->pathData}");
        }

        $extension = strtolower(pathinfo($this->pathData, PATHINFO_EXTENSION));

        if (!in_array($extension, $this->allowedExtensions, true)) {
            throw new \RuntimeException("Неподдерживаемый формат файла: .{$extension}");
        }

        $this->stats['source_extension'] = $extension;

        // 1. Читаем файл и превращаем каждый лист/csv в набор строк
        $sheets = $extension === 'csv'
            ? $this->readCsv($this->pathData)
            : $this->readExcel($this->pathData);

        if (empty($sheets)) {
            throw new \RuntimeException("Не удалось извлечь данные из файла: {$this->pathData}");
        }

        // 2. Генерируем CREATE TABLE + INSERT для каждой "таблицы" (листа/csv)
        $createStatements = [];
        $insertStatements = [];
        $usedNames = [];

        foreach ($sheets as $sheetName => $rows) {
            if (empty($rows)) {
                continue;
            }

            $tableName = $this->makeUniqueTableName($this->sanitizeIdentifier($sheetName), $usedNames);

            $header = array_shift($rows); // первая строка — заголовки колонок
            $columns = $this->normalizeColumnNames($header);

            if (empty($columns)) {
                continue;
            }

            $createStatements[] = $this->buildCreateTable($tableName, $columns, $rows);
            $insertStatements[] = $this->buildInsertStatements($tableName, $columns, $rows);

            $this->stats['tables'][$tableName] = count($rows);
        }

        $this->stats['create_count'] = count($createStatements);
        $this->stats['insert_batches'] = count($insertStatements);

        // 3. Формируем итоговый .sql контент — так же, как в MySqlDataHandler
        $content = "-- Сгенерировано для DuckDB (из Excel/CSV)\n";
        $content .= "-- Источник: {$this->uploadFile->file_path}\n";
        $content .= "-- Дата: " . now()->toDateTimeString() . "\n";
        $content .= "-- Таблиц: " . count($createStatements) . "\n\n";
        $content .= implode("\n\n", $createStatements) . "\n\n";
        $content .= implode("\n\n", $insertStatements) . "\n";

        // 4. Сохраняем .sql так же, как оригинал
        $this->sqlFilePath = $this->saveSqlFile($content);

        // 5. Вызываем ТОТ ЖЕ sql_to_duck.py, что и MySqlDataHandler

        $this->isSuccess = true;

        Log::info('ExcelCsvDataHandler: обработка завершена', [
            'sql_file' => $this->sqlFilePath,
            'stats' => $this->stats,
        ]);
    }

    /**
     * Читает CSV-файл, возвращает одну "виртуальную таблицу"
     * с именем на основе имени файла.
     */
    private function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Не удалось открыть CSV файл: {$path}");
        }

        // Определяем разделитель (запятая или точка с запятой)
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        $tableName = pathinfo($path, PATHINFO_FILENAME);

        return [$tableName => $rows];
    }

    /**
     * Читает Excel-файл (xlsx/xls), возвращает массив ["ИмяЛиста" => [[строки]]]
     */
    private function readExcel(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $result = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            // Важно: getHighestColumn()/getHighestRow() (которые использует toArray()
            // по умолчанию) учитывают ячейки, к которым просто применено форматирование
            // (стиль, заливка, границы), даже если в них нет значения. Excel часто
            // "раздувает" диапазон листа именно так — отсюда лишние col_9, col_10...
            // getHighestDataColumn()/getHighestDataRow() смотрят только на ячейки
            // с реальными данными.
            $highestDataColumn = $sheet->getHighestDataColumn();
            $highestDataRow = $sheet->getHighestDataRow();

            if ($highestDataRow < 1) {
                continue;
            }

            $range = "A1:{$highestDataColumn}{$highestDataRow}";
            $rows = $sheet->rangeToArray($range, null, true, true, false);

            // Убираем полностью пустые строки в конце (на случай "рваных" данных)
            while (!empty($rows) && $this->isRowEmpty(end($rows))) {
                array_pop($rows);
            }

            if (empty($rows)) {
                continue;
            }

            // Доп. страховка: убираем полностью пустые столбцы в конце,
            // если они всё же просочились
            $rows = $this->trimEmptyTrailingColumns($rows);

            if (empty($rows)) {
                continue;
            }

            $result[$sheet->getTitle()] = $rows;
        }

        return $result;
    }

    private function trimEmptyTrailingColumns(array $rows): array
    {
        $maxCols = 0;

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                if ($cell !== null && trim((string) $cell) !== '') {
                    $maxCols = max($maxCols, $i + 1);
                }
            }
        }

        if ($maxCols === 0) {
            return [];
        }

        foreach ($rows as &$row) {
            $row = array_slice($row, 0, $maxCols);
        }
        unset($row);

        return $rows;
    }
    private function isRowEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Приводит имена колонок из заголовка к валидным SQL-идентификаторам,
     * подставляет col_N для пустых/дублирующихся заголовков.
     */
    private function normalizeColumnNames(array $header): array
    {
        $columns = [];
        $used = [];

        foreach ($header as $i => $col) {
            $name = trim((string) $col);
            $name = $name === '' ? "col_{$i}" : $this->sanitizeIdentifier($name);
            $columns[] = $this->makeUniqueTableName($name, $used);
        }

        return $columns;
    }

    private function sanitizeIdentifier(string $name): string
    {
        $name = preg_replace('/[^0-9a-zA-Zа-яА-Я_]/u', '_', trim($name));
        $name = preg_replace('/_+/', '_', $name);
        $name = trim($name, '_');

        if ($name === '') {
            $name = 'col';
        }

        if (preg_match('/^[0-9]/', $name)) {
            $name = 'c_' . $name;
        }

        return mb_strtolower($name);
    }

    private function makeUniqueTableName(string $name, array &$used): string
    {
        $base = $name;
        $i = 1;
        while (in_array($name, $used, true)) {
            $name = $base . '_' . $i;
            $i++;
        }
        $used[] = $name;
        return $name;
    }

    /**
     * Определяет DuckDB-совместимый тип колонки на основе значений столбца.
     */
    private function inferColumnType(array $rows, int $colIndex): string
    {
        $isInteger = true;
        $isDouble = true;
        $hasValue = false;

        foreach ($rows as $row) {
            $value = $row[$colIndex] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $hasValue = true;
            $value = trim((string) $value);

            if (!preg_match('/^-?\d+$/', $value)) {
                $isInteger = false;
            }
            if (!is_numeric($value)) {
                $isDouble = false;
                $isInteger = false;
            }
        }

        if (!$hasValue) {
            return 'VARCHAR';
        }
        if ($isInteger) {
            return 'BIGINT';
        }
        if ($isDouble) {
            return 'DOUBLE';
        }

        return 'VARCHAR';
    }

    private function buildCreateTable(string $tableName, array $columns, array $rows): string
    {
        $columnDefs = [];

        foreach ($columns as $i => $colName) {
            $type = $this->inferColumnType($rows, $i);
            $columnDefs[] = "\"{$colName}\" {$type}";
        }

        $sql = "DROP TABLE IF EXISTS \"{$tableName}\";\n";
        $sql .= "CREATE TABLE \"{$tableName}\" (\n    " . implode(",\n    ", $columnDefs) . "\n);";

        return $sql;
    }

    private function buildInsertStatements(string $tableName, array $columns, array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $columnList = implode(', ', array_map(fn($c) => "\"{$c}\"", $columns));
        $statements = [];
        $chunkSize = 500; // батчами, чтобы не делать гигантский один INSERT

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $valueRows = [];

            foreach ($chunk as $row) {
                $values = [];
                foreach ($columns as $i => $col) {
                    $values[] = $this->formatSqlValue($row[$i] ?? null);
                }
                $valueRows[] = '(' . implode(', ', $values) . ')';
            }

            $statements[] = "INSERT INTO \"{$tableName}\" ({$columnList}) VALUES\n" . implode(",\n", $valueRows) . ';';
        }

        return implode("\n\n", $statements);
    }

    private function formatSqlValue($value): string
    {
        if ($value === null || $value === '') {
            return 'NULL';
        }

        $value = (string) $value;

        // Числа вставляем как есть
        if (preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return $value;
        }

        // Строки — экранируем одинарные кавычки
        $escaped = str_replace("'", "''", $value);
        return "'{$escaped}'";
    }

    private function saveSqlFile(string $content): string
    {
        if (!is_dir($this->outputPath)) {
            if (!mkdir($this->outputPath, 0755, true) && !is_dir($this->outputPath)) {
                throw new \RuntimeException("Не удалось создать директорию: {$this->outputPath}");
            }
        }

        $finalAbsoluteLogPath = $this->outputPath . '/duckdb_ready.sql';

        $result = file_put_contents($finalAbsoluteLogPath, $content);

        if ($result === false) {
            throw new \RuntimeException("Не удалось сохранить SQL файл по пути: {$finalAbsoluteLogPath}");
        }

        return $finalAbsoluteLogPath;
    }


    public function end(): array
    {
        return [
            'file_id'    => $this->uploadFile->id,
            'company_id' => $this->chat->company_id,
            'chat_id'    => $this->chat->id,
            'sql_path' => $this->sqlFilePath,
        ];
    }

    public function getOutputFiles(): array
    {
        return Storage::disk($this->outputDisk)->files($this->outputPath);
    }
}
