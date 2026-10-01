<?php

namespace App\Helpers\DataSource;

use RuntimeException;

class ReadOnlySqlGuard
{

    private const FORBIDDEN_KEYWORDS = [
        'insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate',
        'replace', 'grant', 'revoke', 'attach', 'detach', 'copy', 'export',
        'import', 'install', 'load', 'call', 'merge', 'upsert', 'vacuum',
        'pragma', 'set', 'reset', 'begin', 'commit', 'rollback',
    ];

    public static function sanitize(string $sql, ?int $maxRows): string
    {
        $clean = trim($sql);

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

        if ($maxRows !== null && !preg_match('/\blimit\s+\d+/i', $clean)) {
            $clean .= ' LIMIT '.$maxRows;
        }

        return $clean;
    }

    public static function stripTrailingLimit(string $sql): string
    {
        return preg_replace('/\s+limit\s+\d+(\s+offset\s+\d+)?\s*$/i', '', trim($sql)) ?? $sql;
    }

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
