<?php

namespace App\Helpers\DataSource\Handlers;

use App\Helpers\PythonRunner;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class TableDataHandler
{
    public $filePath;
    public $outputDisk = 'company';
    public $outputPath;
    public $dbFilePath;

    public $sqlFilePath;
    public $stats = [];

    private array $allowedExtensions = ['csv', 'xlsx', 'xls'];

    public function __construct(string $filePath, string $outputPath,$dbFilePath)
    {
        ini_set('memory_limit', '2G');
        set_time_limit(300);

        $this->filePath = $filePath;
        $this->outputPath = $outputPath;
        $this->dbFilePath = $dbFilePath;
    }

    /**
     * Запускает процесс обработки и возвращает массив с результатом.
     *
     * @return array
     */
    public function handle(): array
    {
        try {
            $this->process();

            return [
                'success' => true,
                'message' => 'Обработка файла успешно завершена.',
                'sql_file' => $this->sqlFilePath,
                'db_file' => $this->dbFilePath,
                'stats' => $this->stats,
            ];
        } catch (Throwable $e) {
            Log::error('TableDataHandler: ошибка обработки файла', [
                'error' => $e->getMessage(),
                'file' => $this->filePath,
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Ошибка при обработке файла: ' . $e->getMessage(),
            ];
        }
    }

    private function createDuckdbDatabase(string $sqlPath): array
    {
        $path = "/home/azim/projects/Datavue/app/Helpers/DataSource/sql_to_duck.py";

        $runner = new PythonRunner(
            $path,
            [
                '--sql'  => $sqlPath,
                '--path' => $this->dbFilePath, // без повторного /data.duckdb
            ]
        );

        $result = $runner->run();

        $success = ($result['exit_code'] ?? 1) === 0;

        return [
            'success' => $success,
            'message' => $success
                ? 'DuckDB база успешно создана.'
                : implode("\n", $result['output'] ?? []),
            'raw' => $result,
        ];
    }

    private function process(): void
    {
        if (!file_exists($this->filePath)) {
            throw new \RuntimeException("Исходный файл не найден: {$this->filePath}");
        }

        if (!is_readable($this->filePath)) {
            throw new \RuntimeException("Файл не доступен для чтения: {$this->filePath}");
        }

        $extension = strtolower(pathinfo($this->filePath, PATHINFO_EXTENSION));

        if (!in_array($extension, $this->allowedExtensions, true)) {
            throw new \RuntimeException("Неподдерживаемый формат файла: .{$extension}");
        }

        $this->stats['source_extension'] = $extension;

        // 1. Читаем файл и превращаем каждый лист/csv в набор строк
        $sheets = $extension === 'csv'
            ? $this->readCsv($this->filePath)
            : $this->readExcel($this->filePath);

        if (empty($sheets)) {
            throw new \RuntimeException("Не удалось извлечь данные из файла: {$this->filePath}");
        }

        // 2. Генерируем CREATE TABLE + INSERT для каждой таблицы (листа/csv)
        $createStatements = [];
        $insertStatements = [];
        $usedNames = [];

        foreach ($sheets as $sheetName => $rows) {
            if (empty($rows)) {
                continue;
            }

            $tableName = $this->makeUniqueTableName($this->sanitizeIdentifier($sheetName), $usedNames);

            $header = array_shift($rows); // Первая строка — заголовки
            $columns = $this->normalizeColumnNames($header);

            if (empty($columns)) {
                continue;
            }

            $createStatements[] = $this->buildCreateTable($tableName, $columns, $rows);
            $insertStatements[] = $this->buildInsertStatements($tableName, $columns, $rows);

            $this->stats['tables'][$tableName] = count($rows);
        }

        if (empty($createStatements)) {
            throw new \RuntimeException("Ни одна таблица не была сформирована из файла: {$this->filePath}");
        }

        $this->stats['create_count'] = count($createStatements);
        $this->stats['insert_batches'] = count($insertStatements);

        // 3. Формируем итоговый SQL-контент
        $content = "-- Сгенерировано для DuckDB (из Excel/CSV)\n";
        $content .= "-- Источник: {$this->filePath}\n";
        $content .= "-- Дата: " . now()->toDateTimeString() . "\n";
        $content .= "-- Таблиц: " . count($createStatements) . "\n\n";
        $content .= implode("\n\n", $createStatements) . "\n\n";
        $content .= implode("\n\n", $insertStatements) . "\n";

        $this->sqlFilePath = $this->saveSqlFile($content);

        $duckdbResult = $this->createDuckdbDatabase($this->sqlFilePath);

        if (!($duckdbResult['success'] ?? false)) {
            throw new \RuntimeException(
                'Не удалось создать DuckDB базу: ' . ($duckdbResult['message'] ?? 'неизвестная ошибка python-скрипта')
            );
        }

        if (!file_exists($this->dbFilePath)) {
            throw new \RuntimeException("Python-скрипт завершился без ошибок, но файл базы не найден: {$this->dbFilePath}");
        }

        Log::info('TableDataHandler: обработка завершена', [
            'sql_file' => $this->sqlFilePath,
            'db_file' => $this->dbFilePath,
            'stats' => $this->stats,
        ]);
    }

    private function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Не удалось открыть CSV файл: {$path}");
        }

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

    private function readExcel(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $result = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $highestDataColumn = $sheet->getHighestDataColumn();
            $highestDataRow = $sheet->getHighestDataRow();

            if ($highestDataRow < 1) {
                continue;
            }

            $range = "A1:{$highestDataColumn}{$highestDataRow}";
            $rows = $sheet->rangeToArray($range, null, true, true, false);

            while (!empty($rows) && $this->isRowEmpty(end($rows))) {
                array_pop($rows);
            }

            if (empty($rows)) {
                continue;
            }

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
        $chunkSize = 500;

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

        if (preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return $value;
        }

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

    public function getOutputFiles(): array
    {
        return Storage::disk($this->outputDisk)->files($this->outputPath);
    }
}
