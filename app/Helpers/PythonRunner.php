<?php

namespace App\Helpers;

class PythonRunner
{
    public ?string $command = null;

    private string $pythonBinary;

    /** Максимальное время выполнения процесса, сек. */
    private int $timeoutSeconds;

    /** Ограничения ulimit для процесса: ключ ulimit => значение. */
    private array $limits;

    /**
     * Ограничения для кода, написанного человеком.
     *
     * Это не песочница, а страховка от очевидного: бесконечного выделения
     * памяти, форк-бомбы и записи гигабайтов на диск. От намеренной атаки
     * защищает изоляция процесса на уровне системы, а не эти лимиты.
     *
     * @return array<string, int>
     */
    public static function restrictedLimits(): array
    {
        return [
            'v' => 1_500_000, // виртуальная память, КБ (~1.5 ГБ: pandas требует запаса)
            'u' => 64,        // число процессов
            'f' => 200_000,   // размер создаваемых файлов, блоки по 1 КБ
        ];
    }

    public function __construct(
        ?string $pathPython = null,
        array $args = [],
        ?string $python = null,
        int $timeoutSeconds = 60,
        array $limits = []
    ) {
        $this->pythonBinary = $python ?: base_path('venv/bin/python');

        if (!file_exists($this->pythonBinary)) {
            $this->pythonBinary = 'python3';
        }

        $this->timeoutSeconds = $timeoutSeconds;
        $this->limits = $limits;

        if ($pathPython !== null) {
            $scriptDirectory = dirname($pathPython);

            $extraArgs = $this->buildArgs($args);

            $this->command = sprintf(
                'cd %s && %s %s%s',
                escapeshellarg($scriptDirectory),
                escapeshellarg($this->pythonBinary),
                escapeshellarg($pathPython),
                $extraArgs
            );
        }
    }

    /**
     * Старый запуск Python-файла
     */
    public function run(): array
    {
        if ($this->command === null) {
            return [
                'command'   => null,
                'output'    => ['Python script path is not specified'],
                'exit_code' => 1,
            ];
        }

        return $this->execute($this->command);
    }

    /**
     * Запуск Python-кода напрямую
     */
    public function runCode(string $code, array $args = []): array
    {
        $extraArgs = $this->buildArgs($args);

        // код передаём через временный файл, а не -c:
        // -c имеет ограничения на длину аргумента командной строки
        // и на некоторых системах ломает многострочные строки/кавычки
        $tmpFile = tempnam(sys_get_temp_dir(), 'pyrun_') . '.py';
        file_put_contents($tmpFile, $code);

        $command = sprintf(
            '%s %s%s',
            escapeshellarg($this->pythonBinary),
            escapeshellarg($tmpFile),
            $extraArgs
        );

        $result = $this->execute($command);

        @unlink($tmpFile);

        return $result;
    }

    private function buildArgs(array $args): string
    {
        $extraArgs = '';

        foreach ($args as $key => $value) {
            if (is_string($key)) {
                $extraArgs .= ' ' . escapeshellarg($key);

                if ($value !== null && $value !== '') {
                    $extraArgs .= ' ' . escapeshellarg($value);
                }
            } else {
                $extraArgs .= ' ' . escapeshellarg($value);
            }
        }

        return $extraArgs;
    }

    /**
     * Общий исполнитель команд с жёстким таймаутом.
     * Без таймаута зависший дочерний процесс (например, из-за
     * HTTP-запроса Python-скрипта обратно на однопоточный dev-сервер
     * Laravel) вешает весь запрос бесконечно.
     */
    private function execute(string $command): array
    {
        $output = [];
        $exitCode = 0;

        // Ограничение потоков вычислительных библиотек.
        //
        // OpenBLAS внутри numpy по умолчанию поднимает поток на каждое ядро —
        // на сервере с 64 ядрами это 64 потока при лимите хостинга в 40
        // процессов на пользователя. Итог: «import pandas» не падает с внятной
        // ошибкой, а зависает намертво, и виджет молча уходит в таймаут.
        //
        // Одного потока достаточно: тяжёлые вычисления идут в SQL, а pandas
        // здесь только раскладывает готовый результат.
        $environment = 'OPENBLAS_NUM_THREADS=1 OMP_NUM_THREADS=1 '
            .'MKL_NUM_THREADS=1 NUMEXPR_NUM_THREADS=1 ';

        // ulimit ставится внутри той же оболочки, что запускает python:
        // ограничение действует на дочерний процесс и всё его потомство.
        $ulimits = '';

        foreach ($this->limits as $flag => $value) {
            $ulimits .= sprintf('ulimit -%s %d; ', $flag, (int) $value);
        }

        $wrapped = sprintf(
            'timeout --signal=KILL %d bash -c %s',
            $this->timeoutSeconds,
            escapeshellarg($ulimits.$environment.$command)
        );

        exec($wrapped . ' 2>&1', $output, $exitCode);

        if ($exitCode === 137) {
            $output[] = sprintf(
                'Процесс превысил лимит времени (%d сек.) и был принудительно завершён. '
                . 'Если скрипт делает HTTP-запрос обратно на этот же сервер (например, к /connection), '
                . 'убедитесь, что сервер многопоточный (php-fpm/Octane), иначе возникает взаимная блокировка.',
                $this->timeoutSeconds
            );
        }

        return [
            'command' => $wrapped,
            'output' => $output,
            'exit_code' => $exitCode,
        ];
    }
}
