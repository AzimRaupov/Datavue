<?php

namespace App\Helpers\Alert;

class AlertOutputValidator
{

    public function validate(array $output): array
    {
        $lines = array_values(array_filter($output, fn ($line) => trim((string) $line) !== ''));

        if ($lines === []) {
            return ['ok' => false, 'error' => 'Код алерта не вывел ничего.'];
        }

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
