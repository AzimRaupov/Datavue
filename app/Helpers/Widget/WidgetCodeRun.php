<?php

namespace App\Helpers\Widget;

use App\Helpers\DataSource\CodeTemplater;
use App\Helpers\PythonRunner;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use RuntimeException;

class WidgetCodeRun
{
    /**
     * Сколько ждём код, написанный человеком.
     *
     * Меньше, чем у сгенерированного (60 с): черновик прогоняют в интерфейсе
     * и ждут ответа, а зависший цикл в чужом коде не должен занимать воркер
     * на минуту.
     */
    public const MANUAL_TIMEOUT = 30;

    public function run(
        DashboardWidget $widget,
        DataSource $dataSource
    ): array {
        // Исходник правды у ручного виджета — колонка code, у сгенерированного
        // по-прежнему файл. resolveCode() знает этот порядок.
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

    /**
     * Выполняет тело main() как есть, без обращения к базе.
     *
     * Нужно предпросмотру в конструкторе: автор жмёт «Выполнить» до того, как
     * код сохранён, и должен увидеть либо данные, либо ошибку — а не записать
     * в дашборд заведомо сломанный виджет.
     *
     * @param bool $restricted Запускать с ограничениями по памяти и процессам.
     */
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
            // Лимиты применяются только к коду, написанному человеком:
            // поведение генерации остаётся ровно прежним.
            limits: $restricted ? PythonRunner::restrictedLimits() : []
        );

        return $runner->runCode($fullCode);
    }

    /**
     * Собирает полный скрипт: импорты, функция query() с реальными кредами
     * источника, тело main() и его вызов.
     *
     * Один и тот же сбор для ручного и сгенерированного кода — иначе автор
     * писал бы код под один рантайм, а исполнялся бы он в другом.
     */
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
