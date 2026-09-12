<?php

namespace App\Helpers\Alert;

use App\Helpers\DataSource\CodeTemplater;
use App\Helpers\PythonRunner;
use App\Models\Alert;
use App\Models\DataSource;
use RuntimeException;

/**
 * Выполняет Python-условие алерта (mode python).
 *
 * Тот же путь, что у ручных виджетов (WidgetCodeRun): импорты и функция
 * query() с реальными кредами источника собирает CodeTemplater, тело main()
 * пишет автор, синтаксис проверяется до запуска, процесс идёт под лимитами
 * PythonRunner::restrictedLimits() — это код человека, а не сгенерированный
 * пайплайном, доверия к нему ровно столько же, сколько ручному виджету.
 */
class AlertCodeRun
{
    public function __construct(
        private AlertOutputValidator $validator = new AlertOutputValidator(),
    ) {
    }

    /**
     * @return array{ok: bool, triggered?: bool, value?: mixed, message?: ?string, rows?: array, error?: string}
     */
    public function run(Alert $alert, DataSource $dataSource, int $timeoutSeconds): array
    {
        $code = trim((string) $alert->code);

        if ($code === '') {
            return ['ok' => false, 'error' => 'Код алерта пуст.'];
        }

        try {
            $fullCode = $this->buildFullCode($code, $dataSource);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $syntaxError = $this->checkSyntax($fullCode);

        if ($syntaxError) {
            return ['ok' => false, 'error' => "Ошибка синтаксиса в коде алерта:\n{$syntaxError}"];
        }

        $runner = new PythonRunner(
            timeoutSeconds: $timeoutSeconds,
            limits: PythonRunner::restrictedLimits()
        );

        $result = $runner->runCode($fullCode);

        if (($result['exit_code'] ?? 1) !== 0) {
            return [
                'ok' => false,
                'error' => 'Код завершился с ошибкой: '.implode("\n", $result['output'] ?? []),
            ];
        }

        return $this->validator->validate($result['output'] ?? []);
    }

    private function buildFullCode(string $codeMain, DataSource $dataSource): string
    {
        $codeTemplater = new CodeTemplater($dataSource->id);

        $parts = [
            $codeTemplater->getLibraries(),
            $codeTemplater->getQueryTemplate(false),
            $this->normalizeCode($codeMain),
            "if __name__ == \"__main__\":\n    main()\n",
        ];

        return implode("\n", array_map('rtrim', $parts))."\n";
    }

    private function normalizeCode(string $code): string
    {
        $code = preg_replace('/^\x{FEFF}/u', '', $code);
        $code = str_replace(["\r\n", "\r"], "\n", $code);
        $code = str_replace("\t", '    ', $code);

        return trim($code)."\n";
    }

    private function checkSyntax(string $code): ?string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'alert_').'.py';
        file_put_contents($tmpFile, $code);

        $output = [];
        $exitCode = 0;

        exec('python3 -m py_compile '.escapeshellarg($tmpFile).' 2>&1', $output, $exitCode);

        @unlink($tmpFile);

        return $exitCode !== 0 ? implode("\n", $output) : null;
    }
}
