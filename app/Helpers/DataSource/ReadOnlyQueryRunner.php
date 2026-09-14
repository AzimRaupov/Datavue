<?php

namespace App\Helpers\DataSource;

use RuntimeException;
use Throwable;

class ReadOnlyQueryRunner
{
    public const MAX_ROWS = 200;

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

    private function sanitize(string $sql): string
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
