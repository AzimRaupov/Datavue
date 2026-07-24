<?php

namespace App\Helpers\DataSource\Handlers;

use App\Helpers\PythonRunner;
use App\Models\AiChatMessage;
use App\Models\DataSourceExtraction;
use App\Models\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class MySqlDataHandler
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

        $this->chat = $chat;
        $this->uploadFile = $uploadFile;
        $this->storage = $storage;

        $this->pathData = $this->uploadFile->file_path;
        $this->outputPath = $storage . '/extracted_data';

        Log::info('MySqlDataHandler: начало обработки', [
            'chat_id' => $chat->id,
            'upload_id' => $uploadFile->id,
            'source' => $this->pathData,
            'output_path' => $this->outputPath,
        ]);

        // ВАЖНО: try/catch убран намеренно.
        // Раньше исключение здесь гасилось (isSuccess=false, errorMessage),
        // а объект всё равно считался "валидным" и использовался дальше
        // (->end() возвращал sql_path=null), из-за чего настоящая ошибка
        // терялась и в контроллере вылезал фатал "Attempt to read property
        // id on null". Теперь исключение пробрасывается наверх, в
        // DataSourceRouter::handle(), где оно уже логируется и должно
        // пробрасываться дальше (см. правку в DataSourceRouter).
        $this->process();
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

        // Сортируем CREATE TABLE по зависимостям (FOREIGN KEY): родители раньше потомков
        $createStatements = $this->sortTablesByDependencies($createStatements);

        // ИСПРАВЛЕНИЕ (связи не создавались):
        // Раньше DROP TABLE IF EXISTS вклеивался прямо перед каждым CREATE TABLE
        // (внутри normalizeForDuckdb), то есть DROP шёл в том же порядке,
        // что и CREATE — родитель раньше потомка. Это ломает связи при
        // повторном импорте: если таблицы уже существуют в DuckDB с ранее
        // созданными FOREIGN KEY, DROP TABLE родителя ("customers") падает
        // с ошибкой, потому что на него всё ещё ссылается FK потомка
        // ("orders"), который на этот момент ещё не дропнут. В итоге весь
        // DROP+CREATE для родителя не выполнялся, таблица оставалась
        // старой/отсутствующей, а связь на неё у потомка не создавалась.
        //
        // Теперь DROP-статements вынесены в отдельный блок в НАЧАЛЕ файла
        // и идут в ОБРАТНОМ порядке зависимостей (сначала дропаются
        // потомки, потом родители) — ровно как того требует ссылочная
        // целостность при удалении. CREATE-statements, как и раньше, идут
        // в прямом порядке (сначала родители, потом потомки).
        // ИСПРАВЛЕНИЕ (связи не создавались, часть 2):
        // extractTableNamesInOrder искала только "CREATE TABLE" и строила
        // DROP-список только из таблиц. Но $createStatements на этом этапе
        // уже мог содержать CREATE VIEW (см. фикс в sortTablesByDependencies
        // ниже — раньше view вообще молча терялись). Если view не дропать
        // перед повторным импортом, DuckDB падает с "already exists" на
        // CREATE VIEW, скрипт обрывается посреди файла, и все таблицы
        // ПОСЛЕ этого места в файле не создаются вовсе — соответственно
        // все FK-связи, ссылающиеся на них, тоже не создаются. Поэтому
        // теперь отдельно собираем DROP VIEW (сначала, т.к. view может
        // зависеть от таблиц) и DROP TABLE (потомки раньше родителей).
        $entities = $this->extractCreateEntitiesInOrder($createStatements);

        $viewNames = array_column(
            array_filter($entities, fn ($e) => $e['type'] === 'VIEW'),
            'name'
        );
        $tableNames = array_column(
            array_filter($entities, fn ($e) => $e['type'] === 'TABLE'),
            'name'
        );

        $dropStatements = array_map(
            fn ($viewName) => 'DROP VIEW IF EXISTS ' . $this->quoteIdentifier($viewName) . ';',
            array_reverse($viewNames)
        );

        $dropStatements = array_merge($dropStatements, array_map(
            fn ($tableName) => 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($tableName) . ';',
            array_reverse($tableNames)
        ));

        // Добавляем точки с запятой к CREATE/INSERT
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
        $content .= "-- DROP (в обратном порядке зависимостей, чтобы не ловить ошибку FK при повторном импорте)\n";
        $content .= implode("\n", $dropStatements) . "\n\n";
        $content .= implode("\n\n", $createStatements) . "\n\n";
        $content .= implode("\n\n", $insertStatements) . "\n";

        $this->sqlFilePath = $this->saveSqlFile($content);

        $this->isSuccess = true;

        Log::info('MySqlDataHandler: обработка завершена', [
            'sql_file' => $this->sqlFilePath,
            'stats' => $this->stats,
        ]);
    }

    /**
     * Извлекает имена CREATE TABLE / CREATE VIEW стейтментов вместе с их
     * типом, СТРОГО в том порядке, в котором они переданы (после
     * топологической сортировки). Используется для построения списка
     * DROP-стейтментов с правильным ключевым словом (DROP TABLE / DROP VIEW).
     *
     * Возвращает массив вида [['type' => 'TABLE'|'VIEW', 'name' => '...'], ...]
     */
    private function extractCreateEntitiesInOrder(array $createStatements): array
    {
        $entities = [];

        foreach ($createStatements as $stmt) {
            if (preg_match(
                '/CREATE\s+VIEW\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"([^"]+)"|(\w+))/i',
                $stmt,
                $m
            )) {
                $entities[] = ['type' => 'VIEW', 'name' => $m[1] ?? $m[2]];
                continue;
            }

            if (preg_match(
                '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"([^"]+)"|(\w+))/i',
                $stmt,
                $m
            )) {
                $entities[] = ['type' => 'TABLE', 'name' => $m[1] ?? $m[2]];
            }
        }

        return $entities;
    }

    private function sortTablesByDependencies(array $createStatements): array
    {
        $tables = [];
        $dependencies = [];
        $others = [];

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
            } else {
                // ИСПРАВЛЕНИЕ (CREATE VIEW терялись бесследно):
                // Раньше любой стейтмент, не подошедший под "CREATE TABLE"
                // (в первую очередь CREATE VIEW), просто пропускался этим
                // if/else и никуда не сохранялся. Ниже $result строился
                // ИСКЛЮЧИТЕЛЬНО из $tables, поэтому такие стейтменты
                // молча выпадали из итогового SQL-файла целиком (не было
                // даже DROP для них, они просто никогда не выполнялись).
                // Складываем их отдельно и возвращаем в конец результата —
                // view обычно зависит от уже созданных таблиц.
                $others[] = $stmt;
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

        // Возвращаем CREATE VIEW и прочие non-table стейтменты в конец —
        // раньше они здесь безвозвратно терялись (см. комментарий выше).
        foreach ($others as $stmt) {
            $result[] = $stmt;
        }

        $this->stats['sorted_tables'] = array_keys($tables);
        $this->stats['other_statements_preserved'] = count($others);

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

        // 1.1 Удаляем COMMENT 'текст' (колоночный и табличный).
        // ВАЖНО: делаем это ДО замены типов (TEXT->VARCHAR и т.д.), иначе
        // регэкспы типов могут задеть слова внутри самого текста комментария.
        // Без этого шага DuckDB падал с "Parser Error: syntax error at or
        // near COMMENT", потому что MySQL-комментарии к столбцам/таблицам
        // не входят в синтаксис DuckDB.
        $sql = preg_replace("/\s*COMMENT\s*=?\s*'(?:[^'\\\\]|\\\\.)*'/is", '', $sql);

        // 1.2 Нулевые MySQL-даты невалидны в DuckDB — превращаем в NULL.
        // Без этого DuckDB падал с "Conversion Error: date field value out
        // of range: 0000-00-00".
        $sql = preg_replace("/'0000-00-00 00:00:00'/", 'NULL', $sql);
        $sql = preg_replace("/'0000-00-00'/", 'NULL', $sql);

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

        // 11.1 ИСПРАВЛЕНИЕ (связи не создавались):
        // MySQL пишет уникальные индексы как "UNIQUE KEY `name` (`col`)".
        // Это НЕ валидный синтаксис DuckDB — DuckDB понимает только
        // "UNIQUE (col)" (без имени индекса). Из-за этого CREATE TABLE
        // падал целиком с ошибкой парсера, таблица не создавалась вообще,
        // а любой FOREIGN KEY, ссылающийся на неё, тоже не мог быть создан
        // (referenced-таблицы просто не существовало). Приводим к валидному
        // виду: "UNIQUE KEY "name" ("col")" -> "UNIQUE ("col")".
        //
        // ДОПОЛНЕНИЕ: mysqldump также генерирует форму "UNIQUE INDEX `name`
        // (`col`)" — синтаксически то же самое, но раньше не покрывалось
        // регэкспом (искали только слово KEY). Она так же невалидна для
        // DuckDB и так же роняла весь CREATE TABLE. Обрабатываем оба варианта.
        $sql = $this->replaceOutsideStrings(
            $sql,
            '/\bUNIQUE\s+(?:KEY|INDEX)\s+(?:"[^"]+"|\w+)\s*(\([^)]+\))/i',
            'UNIQUE $1'
        );

        // Нормализуем CONSTRAINT блоки
        // CONSTRAINT fk_name PRIMARY KEY (col) -> PRIMARY KEY (col)
        $sql = preg_replace('/CONSTRAINT\s+["\w]+\s+PRIMARY\s+KEY/i', 'PRIMARY KEY', $sql);

        // CONSTRAINT fk_name FOREIGN KEY (col) REFERENCES table(col) -> FOREIGN KEY (col) REFERENCES table(col)
        $sql = preg_replace('/CONSTRAINT\s+["\w]+\s+(FOREIGN\s+KEY)/i', '$1', $sql);

        // CONSTRAINT fk_name UNIQUE (col) -> UNIQUE (col)
        $sql = preg_replace('/CONSTRAINT\s+["\w]+\s+UNIQUE/i', 'UNIQUE', $sql);

        // 11.2 ИСПРАВЛЕНИЕ (связи не создавались):
        // Убираем ON DELETE/ON UPDATE referential actions у FOREIGN KEY
        // (CASCADE/RESTRICT/SET NULL/SET DEFAULT/NO ACTION). Эта СУБД
        // (DuckDB) нужна нам здесь только для чтения/анализа схемы, сами
        // cascade-действия не используются, а разные версии DuckDB
        // по-разному (не)поддерживают эти конструкции в FOREIGN KEY —
        // при несовпадении версии весь CREATE TABLE падал, и связь не
        // создавалась вовсе. Сам FOREIGN KEY ... REFERENCES ... остаётся.
        $sql = $this->replaceOutsideStrings(
            $sql,
            '/\s*ON\s+DELETE\s+(CASCADE|RESTRICT|NO\s+ACTION|SET\s+NULL|SET\s+DEFAULT)/i',
            ''
        );
        $sql = $this->replaceOutsideStrings(
            $sql,
            '/\s*ON\s+UPDATE\s+(CASCADE|RESTRICT|NO\s+ACTION|SET\s+NULL|SET\s+DEFAULT)/i',
            ''
        );

        // 12. Конвертация MySQL backslash-escaping в стандартный SQL (doubled quote).
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

    /**
     * Применяет regex-замену только к частям SQL, которые находятся ВНЕ
     * строковых литералов. Разбивает строку и по одинарным, и по двойным
     * кавычкам, чтобы не задевать содержимое ИМЕНИ КОЛОНКИ/ТАБЛИЦЫ в
     * двойных кавычках.
     *
     * ИСПРАВЛЕНИЕ: раньше splitter учитывал только одинарные кавычки.
     * Из-за этого, например, столбец `` `text` `` (после замены backtick
     * на двойные кавычки превращавшийся в "text") ошибочно попадал под
     * замену /\bTEXT\b/i -> VARCHAR, потому что regex не видел разницы
     * между типом TEXT и квотированным идентификатором "text". В итоге
     * столбец в CREATE TABLE переименовывался, а INSERT продолжал
     * ссылаться на старое имя -> "Binder Error: does not have a column
     * with name text".
     */
    private function replaceOutsideStrings(string $sql, string $pattern, string $replacement): string
    {
        $parts = preg_split(
            '/(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/',
            $sql,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        if ($parts === false) {
            return $sql;
        }
        for ($i = 0; $i < count($parts); $i += 2) {
            $parts[$i] = preg_replace($pattern, $replacement, $parts[$i]);
        }
        return implode('', $parts);
    }

    /**
     * Экранирует имя таблицы для использования в отдельно
     * сгенерированных DROP TABLE-стейтментах.
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', trim($identifier, '"')) . '"';
    }
}
