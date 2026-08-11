<?php

namespace App\Helpers\DataSource;

use RuntimeException;
use Throwable;

/**
 * Выполняет ТОЛЬКО читающие запросы, которые сформировал AI-агент чата.
 *
 * Агент отвечает на вопросы пользователя о его данных, поэтому ему нужен доступ
 * к источнику — но исключительно на чтение и с жёстким ограничением объёма
 * результата, чтобы ответ модели не мог ничего изменить в базе клиента
 * и не утащил в промпт таблицу на миллион строк.
 */
class ReadOnlyQueryRunner
{
    public const MAX_ROWS = 200;

    /**
     * Ключевые слова, которых не должно быть в запросе от агента.
     * Проверяем по границам слов, чтобы не ловить ложные срабатывания
     * на именах колонок вроде "updated_at" или "created_by".
     */
    private const FORBIDDEN_KEYWORDS = [
        'insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate',
        'replace', 'grant', 'revoke', 'attach', 'detach', 'copy', 'export',
        'import', 'install', 'load', 'call', 'merge', 'upsert', 'vacuum',
        'pragma', 'set', 'reset', 'begin', 'commit', 'rollback',
    ];

    public function __construct(
        private ConnectionProviderRouter $router
    ) {
    }

    /**
     * @return array{ok: bool, rows?: array, row_count?: int, truncated?: bool, error?: string}
     */
    public function run(string $sql): array
    {
        try {
            $safeSql = $this->sanitize($sql);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            $rows = $this->router->query($safeSql);
        } catch (Throwable $e) {
            // Ошибку отдаём агенту текстом — он сможет исправить запрос
            // и попробовать ещё раз, вместо того чтобы молча выдумать ответ.
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $rows = $this->normalizeRows($rows);
        $truncated = count($rows) > self::MAX_ROWS;

        return [
            'ok' => true,
            'rows' => array_slice($rows, 0, self::MAX_ROWS),
            'row_count' => count($rows),
            'truncated' => $truncated,
        ];
    }

    /**
     * Пропускает только одиночный SELECT/WITH и навешивает LIMIT.
     */
    private function sanitize(string $sql): string
    {
        $clean = trim($sql);
        // Срезаем комментарии, чтобы через них нельзя было спрятать вторую инструкцию.
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

        // Если агент не ограничил выборку сам — ограничиваем за него.
        if (!preg_match('/\blimit\s+\d+/i', $clean)) {
            $clean .= ' LIMIT '.self::MAX_ROWS;
        }

        return $clean;
    }

    private function normalizeRows($rows): array
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
