<?php

namespace App\Helpers\Alert;

/**
 * Контракт вывода Python-алерта (mode python).
 *
 * main() сам печатает ровно один JSON-объект — тот же приём, что у ручных
 * виджетов (см. WidgetCodeRun, codeTemplates.js FOOTER):
 *
 *   { "triggered": bool, "value": number|string|null, "message": string|null,
 *     "rows": [ {...}, ... ] }
 *
 * Отсутствие "triggered", лишний мусор в stdout или невалидный JSON — это
 * ОШИБКА проверки, а не «не сработало». Смешать эти два случая значит
 * получить алерт, который выглядит здоровым, пока молча ничего не проверяет.
 */
class AlertOutputValidator
{
    /**
     * @param array<int, string> $output Строки stdout процесса
     *
     * @return array{ok: bool, triggered?: bool, value?: mixed, message?: ?string, rows?: array, error?: string}
     */
    public function validate(array $output): array
    {
        $lines = array_values(array_filter($output, fn ($line) => trim((string) $line) !== ''));

        if ($lines === []) {
            return ['ok' => false, 'error' => 'Код алерта не вывел ничего.'];
        }

        // Как и у виджетов: контракт — ровно одна строка JSON. Что угодно
        // ещё в stdout (print для отладки, traceback) — это уже не «результат»,
        // а сигнал, что автор не убрал отладочный вывод или скрипт упал.
        if (count($lines) > 1) {
            return [
                'ok' => false,
                'error' => 'Код алерта вывел больше одной строки — уберите отладочный print().',
            ];
        }

        $decoded = json_decode($lines[0], true);

        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return ['ok' => false, 'error' => 'Вывод кода алерта не является JSON-объектом.'];
        }

        if (!array_key_exists('triggered', $decoded) || !is_bool($decoded['triggered'])) {
            return [
                'ok' => false,
                'error' => 'В выводе нет булева поля "triggered" — код обязан явно решить, сработало условие или нет.',
            ];
        }

        return [
            'ok' => true,
            'triggered' => $decoded['triggered'],
            'value' => $decoded['value'] ?? null,
            'message' => $decoded['message'] ?? null,
            'rows' => is_array($decoded['rows'] ?? null) ? $decoded['rows'] : [],
        ];
    }
}
