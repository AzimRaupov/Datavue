<?php

namespace App\Helpers\Widget;

use App\Helpers\PythonRunner;
use Illuminate\Support\Facades\Log;

class WidgetCodeInspector
{

    public const MAX_LENGTH = 20000;

    private const TIMEOUT = 15;

    public function inspect(?string $code): array
    {
        $code = (string) $code;

        $errors = $this->quickChecks($code);

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        return $this->inspectAst($code);
    }

    private function quickChecks(string $code): array
    {
        $errors = [];

        if (trim($code) === '') {
            $errors[] = 'Код виджета пуст.';

            return $errors;
        }

        if (mb_strlen($code) > self::MAX_LENGTH) {
            $errors[] = sprintf(
                'Код длиннее %d символов. Вынесите вычисления в SQL — считать в базе быстрее и короче.',
                self::MAX_LENGTH
            );
        }

        if (!preg_match('/^\s*def\s+main\s*\(\s*\)\s*:/m', $code)) {
            $errors[] = 'В коде нет функции «def main():» без аргументов.';
        }

        return $errors;
    }

    private function inspectAst(string $code): array
    {
        $script = resource_path('python/widget_code_inspector.py');

        if (!is_file($script)) {
            Log::error('WidgetCodeInspector: скрипт-инспектор не найден', ['path' => $script]);

            return ['ok' => false, 'errors' => ['Проверка кода недоступна: инспектор не установлен.']];
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'inspect_') . '.py';
        file_put_contents($tmpFile, $code);

        try {
            $runner = new PythonRunner(
                pathPython: $script,
                args: [$tmpFile],
                timeoutSeconds: self::TIMEOUT
            );

            $result = $runner->run();
        } finally {
            @unlink($tmpFile);
        }

        $output = trim(implode("\n", $result['output'] ?? []));
        $decoded = json_decode($output, true);

        if (!is_array($decoded) || !array_key_exists('ok', $decoded)) {
            Log::error('WidgetCodeInspector: инспектор не вернул результат', [
                'exit_code' => $result['exit_code'] ?? null,
                'output' => mb_substr($output, 0, 500),
            ]);

            return [
                'ok' => false,
                'errors' => ['Не удалось проверить код. Обратитесь к администратору.'],
            ];
        }

        return [
            'ok' => (bool) $decoded['ok'],
            'errors' => array_values(array_map('strval', $decoded['errors'] ?? [])),
        ];
    }
}
