<?php

namespace App\Helpers;

class PythonRunner
{
    public ?string $command = null;

    private string $pythonBinary;

    private int $timeoutSeconds;

    private array $limits;

    public static function restrictedLimits(): array
    {
        return [
            'v' => 1_500_000,
            'u' => 64,
            'f' => 200_000,
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

    public function runCode(string $code, array $args = []): array
    {
        $extraArgs = $this->buildArgs($args);

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

    private function execute(string $command): array
    {
        $output = [];
        $exitCode = 0;

        $environment = 'OPENBLAS_NUM_THREADS=1 OMP_NUM_THREADS=1 '
            .'MKL_NUM_THREADS=1 NUMEXPR_NUM_THREADS=1 ';

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
