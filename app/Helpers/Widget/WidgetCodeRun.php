<?php

namespace App\Helpers\Widget;

use App\Helpers\DataSource\CodeTemplater;
use App\Helpers\PythonRunner;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use RuntimeException;

class WidgetCodeRun
{

    public function run(
        DashboardWidget $widget,
        DataSource $dataSource
    ): array {
        if (!is_file($widget->code_path)) {
            throw new RuntimeException(
                "Файл с кодом виджета не найден: {$widget->code_path}"
            );
        }

        $codeMain = $this->normalizeCode(
            file_get_contents($widget->code_path)
        );

        $codeTemplater = new CodeTemplater(
            $dataSource->id
        );

        $parts = [
            $codeTemplater->getLibraries(),
            $codeTemplater->getQueryTemplate(false),
            $codeMain,
            "if __name__ == \"__main__\":\n    main()\n",
        ];

        $fullCode = implode(
                "\n",
                array_map('rtrim', $parts)
            ) . "\n";

        $syntaxError = $this->checkSyntax(
            $fullCode
        );

        if ($syntaxError) {
            return [
                'error' => 'Ошибка синтаксиса в сгенерированном коде',
                'details' => $syntaxError,
                'code' => $fullCode,
            ];
        }

        $runner = new PythonRunner();

        return $runner->runCode(
            $fullCode
        );
    }

    private function normalizeCode(
        string $code
    ): string {
        // Удаляем BOM
        $code = preg_replace(
            '/^\x{FEFF}/u',
            '',
            $code
        );

        // Нормализуем переносы строк
        $code = str_replace(
            ["\r\n", "\r"],
            "\n",
            $code
        );

        // Заменяем TAB на 4 пробела
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
