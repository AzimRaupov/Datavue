<?php

namespace App\Helpers\DataHandlers;

use App\Helpers\PythonRunner;
use App\Models\AiChatMessage;
use App\Models\ExtractedData;
use App\Models\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class SqlDataHandler
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

    public function __construct($chat, $uploadFile, $storage)
    {
        ini_set('memory_limit', '2G'); // Выделяем 2 Гигабайта под скрипт
        set_time_limit(300);
        try {
            $this->chat = $chat;
            $this->uploadFile = $uploadFile;
            $this->storage = $storage;

            $this->pathData = $this->uploadFile->file_path;


            $this->outputPath = $storage . '/extracted_data';
            Log::info('SqlDataHandler: начало обработки', [
                'chat_id' => $chat->id,
                'upload_id' => $uploadFile->id,
                'source' => $this->pathData,
                'output_path' => $this->outputPath,
            ]);

            $this->process();

        } catch (\Throwable $e) {
            $this->isSuccess = false;
            $this->errorMessage = $e->getMessage();

            Log::error('SqlDataHandler: ошибка инициализации', [
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

        $sqlText = file_get_contents($this->pathData);
        if ($sqlText === false) {
            throw new \RuntimeException("Не удалось прочитать файл: {$this->pathData}");
        }

        $statements = $this->splitSqlStatements($sqlText);
        $this->stats['total_statements'] = count($statements);

        // Разделяем CREATE и INSERT
        $createStatements = [];
        $insertStatements = [];
        $createCount = 0;
        $insertCount = 0;

        foreach ($statements as $stmt) {
            $upper = ltrim(strtoupper($stmt));
            if (str_starts_with($upper, 'CREATE TABLE') || str_starts_with($upper, 'CREATE VIEW')) {
                $createStatements[] = $this->normalizeForDuckdb($stmt);
                $createCount++;
            } elseif (str_starts_with($upper, 'INSERT')) {
                $insertStatements[] = $this->normalizeForDuckdb($stmt);
                $insertCount++;
            }
        }

        // Сортируем CREATE TABLE по зависимостям (FOREIGN KEY)
        $createStatements = $this->sortTablesByDependencies($createStatements);

        // Добавляем точки с запятой
        $createStatements = array_map(fn($s) => $s . ';', $createStatements);
        $insertStatements = array_map(fn($s) => $s . ';', $insertStatements);

        $this->stats['create_count'] = $createCount;
        $this->stats['insert_count'] = $insertCount;

        // Формируем содержимое
        $content = "-- Сгенерировано для DuckDB\n";
        $content .= "-- Источник: {$this->uploadFile->file_path}\n";
        $content .= "-- Дата: " . now()->toDateTimeString() . "\n";
        $content .= "-- CREATE таблиц: {$createCount}\n";
        $content .= "-- INSERT запросов: {$insertCount}\n\n";
        $content .= implode("\n\n", $createStatements) . "\n\n";
        $content .= implode("\n\n", $insertStatements) . "\n";

        $this->sqlFilePath = $this->saveSqlFile($content);

        $this->isSuccess = true;

        Log::info('SqlDataHandler: обработка завершена', [
            'sql_file' => $this->sqlFilePath,
            'stats' => $this->stats,
        ]);
    }
    private function sortTablesByDependencies(array $createStatements): array
    {
        $tables = [];
        $dependencies = [];

        // Извлекаем имена таблиц и их зависимости
        foreach ($createStatements as $stmt) {
            // Извлекаем имя таблицы
            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"([^"]+)"|(\w+))/i', $stmt, $m)) {
                $tableName = $m[1] ?? $m[2];
                $tables[$tableName] = $stmt;

                // Извлекаем FOREIGN KEY зависимости
                $deps = [];
                if (preg_match_all('/FOREIGN\s+KEY\s*\([^)]+\)\s*REFERENCES\s+(?:"([^"]+)"|(\w+))/i', $stmt, $refs)) {
                    foreach ($refs[1] as $i => $refTable) {
                        $refTableName = $refTable ?: $refs[2][$i];
                        // Убираем кавычки если есть
                        $refTableName = trim($refTableName, '"');
                        if ($refTableName !== $tableName) {
                            $deps[] = $refTableName;
                        }
                    }
                }
                $dependencies[$tableName] = array_unique($deps);
            }
        }

        // Топологическая сортировка
        $sorted = [];
        $visited = [];
        $temp = [];

        $visit = function($table) use (&$visit, &$visited, &$temp, &$sorted, $dependencies) {
            if (isset($temp[$table])) {
                // Циклическая зависимость - просто пропускаем
                return;
            }
            if (isset($visited[$table])) {
                return;
            }

            $temp[$table] = true;

            foreach ($dependencies[$table] ?? [] as $dep) {
                if (isset($dependencies[$dep])) {
                    $visit($dep);
                }
            }

            unset($temp[$table]);
            $visited[$table] = true;
            $sorted[] = $table;
        };

        foreach (array_keys($dependencies) as $table) {
            if (!isset($visited[$table])) {
                $visit($table);
            }
        }

        // Собираем результат в правильном порядке
        $result = [];
        foreach ($sorted as $tableName) {
            if (isset($tables[$tableName])) {
                $result[] = $tables[$tableName];
            }
        }

        // Добавляем таблицы, которые не были отсортированы
        foreach ($tables as $tableName => $stmt) {
            if (!in_array($stmt, $result)) {
                $result[] = $stmt;
            }
        }

        $this->stats['sorted_tables'] = array_keys($tables);

        return $result;
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

        // Возвращаем абсолютный путь
        return $finalAbsoluteLogPath;
    }


    public function end(){


        return [
            'file_id'=>$this->uploadFile->id,
            'company_id'=>$this->chat->company_id,
            'chat_id'=>$this->chat->id,
            'sql_path'=>$this->sqlFilePath,
        ];

    }


    public function getOutputFiles(): array
    {
        return Storage::disk($this->outputDisk)->files($this->outputPath);
    }


    /**
     * Разбивает SQL-текст на отдельные statements по символу ';'.
     * Учитывает одинарные/двойные кавычки, обратные апострофы,
     * экранирование backslash'ем внутри строк (MySQL-стиль) и комментарии
     * (комментарии игнорируются только вне строковых литералов).
     */
    private function splitSqlStatements(string $sqlText): array
    {
        $statements = [];
        $current = [];
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;
        $escaped = false;

        $length = strlen($sqlText);
        for ($i = 0; $i < $length; $i++) {
            $char = $sqlText[$i];
            $next = $sqlText[$i + 1] ?? '';

            if ($inLineComment) {
                if ($char === "\n") $inLineComment = false;
                continue;
            }
            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            // Если находимся внутри строки и предыдущий символ был '\', то
            // текущий символ считается экранированным и не влияет на состояние кавычек
            if ($escaped) {
                $current[] = $char;
                $escaped = false;
                continue;
            }

            if (($inSingleQuote || $inDoubleQuote) && $char === '\\') {
                $current[] = $char;
                $escaped = true;
                continue;
            }

            // Комментарии распознаём только вне строковых литералов
            if (!$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
                if ($char === '-' && $next === '-') {
                    $inLineComment = true;
                    $i++;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;
                    continue;
                }
            }

            if ($char === "'" && !$inDoubleQuote && !$inBacktick) {
                $inSingleQuote = !$inSingleQuote;
            } elseif ($char === '"' && !$inSingleQuote && !$inBacktick) {
                $inDoubleQuote = !$inDoubleQuote;
            } elseif ($char === '`' && !$inSingleQuote && !$inDoubleQuote) {
                $inBacktick = !$inBacktick;
            }

            if ($char === ';' && !$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
                $stmt = trim(implode('', $current));
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $current = [];
                continue;
            }

            $current[] = $char;
        }

        $stmt = trim(implode('', $current));
        if ($stmt !== '') {
            $statements[] = $stmt;
        }

        return $statements;
    }

    private function normalizeForDuckdb(string $sql): string
    {
        // 1. Идентификаторы
        $sql = str_replace('`', '"', $sql);

        // 2. Удаление MySQL-опций
        $sql = $this->replaceOutsideStrings($sql, '/\s*ENGINE\s*=\s*\w+/i', '');
        $sql = $this->replaceOutsideStrings($sql, '/\s*DEFAULT\s+CHARSET\s*=\s*\w+/i', '');
        $sql = $this->replaceOutsideStrings($sql, '/\s*CHARACTER\s+SET\s+\w+/i', '');
        $sql = $this->replaceOutsideStrings($sql, '/\s*COLLATE\s*=?\s*\w+/i', '');
        $sql = $this->replaceOutsideStrings($sql, '/\s*AUTO_INCREMENT\s*=\s*\d+/i', '');
        $sql = $this->replaceOutsideStrings($sql, '/\s*ON\s+UPDATE\s+CURRENT_TIMESTAMP(\(\))?/i', '');
        $sql = $this->replaceOutsideStrings($sql, '/\bUNSIGNED\b/i', '');
        $sql = $this->replaceOutsideStrings($sql, '/\bZEROFILL\b/i', '');

        // 3. Автоинкремент
        $sql = $this->replaceOutsideStrings($sql, '/\bAUTO_INCREMENT\b/i', '');
        $sql = $this->replaceOutsideStrings($sql, '/\bAUTOINCREMENT\b/i', '');
        $sql = $this->replaceOutsideStrings($sql, '/\bBIGSERIAL\b/i', 'BIGINT');
        $sql = $this->replaceOutsideStrings($sql, '/\bSERIAL\b/i', 'INTEGER');

        // 4. MySQL-функции времени
        $sql = $this->replaceOutsideStrings($sql, '/\bcurrent_timestamp\s*\(\s*\)/i', 'CURRENT_TIMESTAMP');
        $sql = $this->replaceOutsideStrings($sql, '/\bnow\s*\(\s*\)/i', 'CURRENT_TIMESTAMP');
        $sql = $this->replaceOutsideStrings($sql, '/\bunix_timestamp\s*\(\s*\)/i', 'EPOCH(CURRENT_TIMESTAMP)::BIGINT');

        // 5. Числовые типы
        $sql = $this->replaceOutsideStrings($sql, '/\bTINYINT\(1\)/i', 'BOOLEAN');
        $sql = $this->replaceOutsideStrings($sql, '/\bTINYINT\(\d+\)/i', 'TINYINT');
        $sql = $this->replaceOutsideStrings($sql, '/\bSMALLINT\(\d+\)/i', 'SMALLINT');
        $sql = $this->replaceOutsideStrings($sql, '/\bMEDIUMINT\(\d+\)/i', 'INTEGER');
        $sql = $this->replaceOutsideStrings($sql, '/\bINT\(\d+\)/i', 'INTEGER');
        $sql = $this->replaceOutsideStrings($sql, '/\bBIGINT\(\d+\)/i', 'BIGINT');
        $sql = $this->replaceOutsideStrings($sql, '/\bDECIMAL\(\d+,\s*\d+\)/i', 'DECIMAL(18,2)');
        $sql = $this->replaceOutsideStrings($sql, '/\bDECIMAL\b(?!\s*\()/i', 'DECIMAL(18,2)');
        $sql = $this->replaceOutsideStrings($sql, '/\bFLOAT\(\d+,\s*\d+\)/i', 'FLOAT');
        $sql = $this->replaceOutsideStrings($sql, '/\bDOUBLE\(\d+,\s*\d+\)/i', 'DOUBLE');

        // 6. Строковые типы
        $sql = $this->replaceOutsideStrings($sql, '/\bVARCHAR\(\d+\)/i', 'VARCHAR');
        $sql = $this->replaceOutsideStrings($sql, '/\bCHAR\(\d+\)/i', 'VARCHAR');
        $sql = $this->replaceOutsideStrings($sql, '/\bLONGTEXT\b/i', 'VARCHAR');
        $sql = $this->replaceOutsideStrings($sql, '/\bMEDIUMTEXT\b/i', 'VARCHAR');
        $sql = $this->replaceOutsideStrings($sql, '/\bTINYTEXT\b/i', 'VARCHAR');
        $sql = $this->replaceOutsideStrings($sql, '/\bTEXT\b/i', 'VARCHAR');

        // 6.1 Бинарные типы (BLOB) — до этого не обрабатывались вовсе,
        // из-за чего DuckDB падал с "Type with name mediumblob does not exist!"
        $sql = $this->replaceOutsideStrings($sql, '/\bLONGBLOB\b/i', 'BLOB');
        $sql = $this->replaceOutsideStrings($sql, '/\bMEDIUMBLOB\b/i', 'BLOB');
        $sql = $this->replaceOutsideStrings($sql, '/\bTINYBLOB\b/i', 'BLOB');
        $sql = $this->replaceOutsideStrings($sql, '/\bBINARY\(\d+\)/i', 'BLOB');
        $sql = $this->replaceOutsideStrings($sql, '/\bVARBINARY\(\d+\)/i', 'BLOB');
        // "BLOB" без суффикса уже валиден для DuckDB, отдельно заменять не нужно

        // 7. ENUM
        $sql = preg_replace('/\bENUM\s*\(\s*(?:\'[^\']*\'(?:\s*,\s*)?)+\)/i', 'VARCHAR', $sql);

        // 8. JSON
        $sql = $this->replaceOutsideStrings($sql, '/\bJSONB\b/i', 'JSON');

        // 9. Даты
        $sql = $this->replaceOutsideStrings($sql, '/\bDATETIME\(\d+\)/i', 'TIMESTAMP');
        $sql = $this->replaceOutsideStrings($sql, '/\bDATETIME\b/i', 'TIMESTAMP');

        // 10. INSERT IGNORE
        $sql = preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql);

        // 11. PRIMARY KEY и FOREIGN KEY - нормализация
        // Убираем MySQL-специфичные индексы KEY (не PRIMARY, не FOREIGN, не UNIQUE)
        $sql = preg_replace('/,\s*KEY\s+["\w]+\s*\([^)]+\)/i', '', $sql);
        $sql = preg_replace('/,\s*INDEX\s+["\w]+\s*\([^)]+\)/i', '', $sql);

        // Нормализуем CONSTRAINT блоки
        // CONSTRAINT fk_name PRIMARY KEY (col) -> PRIMARY KEY (col)
        $sql = preg_replace('/CONSTRAINT\s+["\w]+\s+PRIMARY\s+KEY/i', 'PRIMARY KEY', $sql);

        // CONSTRAINT fk_name FOREIGN KEY (col) REFERENCES table(col) -> FOREIGN KEY (col) REFERENCES table(col)
        $sql = preg_replace('/CONSTRAINT\s+["\w]+\s+(FOREIGN\s+KEY)/i', '$1', $sql);

        // CONSTRAINT fk_name UNIQUE (col) -> UNIQUE (col)
        $sql = preg_replace('/CONSTRAINT\s+["\w]+\s+UNIQUE/i', 'UNIQUE', $sql);

        // 12. DROP TABLE IF EXISTS перед CREATE TABLE
        if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?("[^"]+"|\w+)/i', $sql, $m)) {
            $tableName = $m[1];
            $sql = "DROP TABLE IF EXISTS {$tableName};\n" . $sql;
        }

        // 12.1 Конвертация MySQL backslash-escaping в стандартный SQL (doubled quote).
        // Выполняется ПОСЛЕ всех replaceOutsideStrings-преобразований, так как
        // они рассчитаны на backslash-escaped строки. После этого шага строки
        // приведены к диалекту, который понимает DuckDB.
        // Без этого шага строки вида 'D\'abondance, Co.' обрывались после
        // backslash-экранированной кавычки и DuckDB падал с
        // "Parser Error: syntax error at or near ..."
        $sql = $this->convertMysqlEscapesToStandard($sql);

        // 13. Очистка
        $sql = preg_replace('/,\s*\)/', ')', $sql);
        $sql = preg_replace('/\s+/', ' ', $sql);


        return trim($sql);
    }

    /**
     * Преобразует MySQL backslash-escaping внутри одинарных кавычек
     * в стандартный SQL escaping (удвоение кавычки), понятный DuckDB.
     * \'  -> ''
     * \\  -> \
     * \n, \r, \t, \0, \Z, \" -> соответствующий символ
     * \%  \_ -> оставляем как есть (используются в LIKE-паттернах)
     * неизвестные escape-последовательности оставляем без изменений
     */
    private function convertMysqlEscapesToStandard(string $sql): string
    {
        $result = '';
        $length = strlen($sql);
        $inSingleQuote = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($inSingleQuote && $char === '\\' && $i + 1 < $length) {
                $next = $sql[$i + 1];
                switch ($next) {
                    case "'":
                        $result .= "''";
                        $i++;
                        break;
                    case '\\':
                        $result .= '\\';
                        $i++;
                        break;
                    case 'n':
                        $result .= "\n";
                        $i++;
                        break;
                    case 'r':
                        $result .= "\r";
                        $i++;
                        break;
                    case 't':
                        $result .= "\t";
                        $i++;
                        break;
                    case '0':
                        $result .= "\0";
                        $i++;
                        break;
                    case '"':
                        $result .= '"';
                        $i++;
                        break;
                    case 'Z':
                        $result .= "\x1a";
                        $i++;
                        break;
                    case '%':
                    case '_':
                        // Оставляем как есть — используется в LIKE
                        $result .= '\\' . $next;
                        $i++;
                        break;
                    default:
                        // Неизвестная escape-последовательность — оставляем без изменений
                        $result .= $char;
                        break;
                }
                continue;
            }

            if ($char === "'") {
                $inSingleQuote = !$inSingleQuote;
            }

            $result .= $char;
        }

        return $result;
    }

    private function replaceOutsideStrings(string $sql, string $pattern, string $replacement): string
    {
        $parts = preg_split("/('(?:[^'\\\\]|\\\\.)*')/", $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $sql;
        }
        for ($i = 0; $i < count($parts); $i += 2) {
            $parts[$i] = preg_replace($pattern, $replacement, $parts[$i]);
        }
        return implode('', $parts);
    }
}
