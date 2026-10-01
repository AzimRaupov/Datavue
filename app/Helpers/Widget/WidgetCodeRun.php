<?php

namespace App\Helpers\Widget;

use App\Helpers\DataSource\CodeTemplater;
use App\Helpers\PythonRunner;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use RuntimeException;

class WidgetCodeRun
{

    public const MANUAL_TIMEOUT = 30;

    public function run(
        DashboardWidget $widget,
        DataSource $dataSource
    ): array {

        $codeMain = $widget->resolveCode();

        if ($codeMain === null) {
            throw new RuntimeException(
                "Код виджета не найден: {$widget->code_path}"
            );
        }

        return $this->runSource(
            codeMain: $codeMain,
            dataSource: $dataSource,
            timeoutSeconds: $widget->isManual() ? self::MANUAL_TIMEOUT : 60,
            restricted: $widget->isManual()
        );
    }

    public function runSource(
        string $codeMain,
        DataSource $dataSource,
        int $timeoutSeconds = 60,
        bool $restricted = false
    ): array {
        $fullCode = $this->buildFullCode($codeMain, $dataSource);

        $syntaxError = $this->checkSyntax($fullCode);

        if ($syntaxError) {
            return [
                'error' => 'Ошибка синтаксиса в коде виджета',
                'details' => $syntaxError,
                'code' => $fullCode,
            ];
        }

        $runner = new PythonRunner(
            timeoutSeconds: $timeoutSeconds,

            limits: $restricted ? PythonRunner::restrictedLimits() : []
        );

        return $runner->runCode($fullCode);
    }

    public function buildFullCode(string $codeMain, DataSource $dataSource): string
    {
        $codeTemplater = new CodeTemplater($dataSource->id);

        $parts = [
            $codeTemplater->getLibraries(),
            $codeTemplater->getQueryTemplate(false),
            $this->normalizeCode($codeMain),
            "if __name__ == \"__main__\":\n    main()\n",
        ];

        return implode(
                "\n",
                array_map('rtrim', $parts)
            ) . "\n";
    }

    private function normalizeCode(
        string $code
    ): string {

        $code = preg_replace(
            '/^\x{FEFF}/u',
            '',
            $code
        );

        $code = str_replace(
            ["\r\n", "\r"],
            "\n",
            $code
        );

        $code = str_replace(
            "\t",
            '    ',
            $code
        );

        return trim($code) . "\n";
    }

    private function checkSyntax(
        string $code
    ): ?string {
        $tmpFile = tempnam(
                sys_get_temp_dir(),
                'widget_'
            ) . '.py';

        file_put_contents(
            $tmpFile,
            $code
        );

        $output = [];
        $exitCode = 0;

        exec(
            'python3 -m py_compile '
            . escapeshellarg($tmpFile)
            . ' 2>&1',
            $output,
            $exitCode
        );

        @unlink($tmpFile);

        if ($exitCode !== 0) {
            return implode(
                "\n",
                $output
            );
        }

        return null;
    }
}
