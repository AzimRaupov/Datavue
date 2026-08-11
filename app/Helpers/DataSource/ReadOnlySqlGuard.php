<?php

namespace App\Helpers\DataSource;

use RuntimeException;

/**
 * Единая проверка «этот SQL безопасно выполнить».
 *
 * Логика была внутри ReadOnlyQueryRunner и обслуживала только чат-агента.
 * С переходом виджетов с Python на SQL появился второй потребитель, и держать
 * две копии правил безопасности нельзя: они разойдутся, и на одном из путей
 * окажется дыра.
 *
 * Правила намеренно грубые и запрещающие: пропускается один SELECT или WITH,
 * без «;», без ключевых слов изменения данных, всегда с ограничением строк.
 */
class ReadOnlySqlGuard
{
    /**
     * Ключевые слова, недопустимые в запросе.
     * Проверяются по границам слов, чтобы не ловить ложные срабатывания
     * на именах колонок вроде "updated_at" или "created_by".
     */
    private const FORBIDDEN_KEYWORDS = [
        'insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate',
        'replace', 'grant', 'revoke', 'attach', 'detach', 'copy', 'export',
        'import', 'install', 'load', 'call', 'merge', 'upsert', 'vacuum',
        'pragma', 'set', 'reset', 'begin', 'commit', 'rollback',
    ];

    /**
     * Приводит запрос к безопасному виду или бросает исключение.
     *
     * @param int|null $maxRows Потолок строк, если запрос не ограничил себя сам.
     *                          null — не дописывать ничего: лимит поставит обёртка
     *                          пагинации, и лишний LIMIT ей только мешает.
     *
     * @throws RuntimeException если запрос не проходит проверку
     */
    public static function sanitize(string $sql, ?int $maxRows): string
    {
        $clean = trim($sql);

        // Срезаем комментарии, чтобы через них нельзя было спрятать
        // вторую инструкцию.
        $clean = preg_replace('/--[^\n]*/', ' ', $clean);
        $clean = preg_replace('/\/\*.*?\*\//s', ' ', $clean);
        $clean = trim($clean);
        $clean = rtrim($clean, "; \t\n\r");

        if ($clean === '') {
            throw new RuntimeException('Пустой SQL-запрос.');
        }

        if (str_contains($clean, ';')) {
            throw new RuntimeException('Разрешён только один SQL-запрос без ";".');
        }

        if (!preg_match('/^\s*(select|with)\b/i', $clean)) {
            throw new RuntimeException('Разрешены только запросы SELECT/WITH.');
        }

        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $clean)) {
                throw new RuntimeException("Запрещённая операция в запросе: {$keyword}.");
            }
        }

        // Не ограничил выборку сам — ограничиваем за него.
        if ($maxRows !== null && !preg_match('/\blimit\s+\d+/i', $clean)) {
            $clean .= ' LIMIT '.$maxRows;
        }

        return $clean;
    }

    /**
     * Убирает завершающий LIMIT/OFFSET из запроса.
     *
     * Нужно при постраничном выводе: если модель поставила в базовом запросе
     * «LIMIT 100», всего строк окажется не больше ста, и дальше первой-второй
     * страницы пользователь не уйдёт — данные будут молча обрезаны.
     */
    public static function stripTrailingLimit(string $sql): string
    {
        return preg_replace('/\s+limit\s+\d+(\s+offset\s+\d+)?\s*$/i', '', trim($sql)) ?? $sql;
    }

    /**
     * Приводит результат любого провайдера к массиву ассоциативных массивов:
     * MySQL и PostgreSQL отдают stdClass, DuckDB — тоже объекты, но через
     * собственный JSON-разбор.
     */
    public static function normalizeRows($rows): array
    {
        if ($rows === null) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            if (is_object($row)) {
                $result[] = get_object_vars($row);
                continue;
            }

            $result[] = is_array($row) ? $row : [$row];
        }

        return $result;
    }
}
