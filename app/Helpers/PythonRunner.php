<?php

namespace App\Helpers;

class PythonRunner
{
    public string $command;

    public function __construct(string $pathPython, array $args = [])
    {
        $scriptDirectory = dirname($pathPython);
        $pythonBinary = base_path('venv/bin/python');

        if (!file_exists($pythonBinary)) {
            $pythonBinary = 'python3';
        }

        $extraArgs = '';

        if (!empty($args)) {
            $extraArgs = ' ' . implode(
                    ' ',
                    array_map('escapeshellarg', $args)
                );
        }

        $this->command = sprintf(
            'cd %s && %s %s%s',
            escapeshellarg($scriptDirectory),
            escapeshellarg($pythonBinary),
            escapeshellarg($pathPython),
            $extraArgs
        );
    }

    public function run(): array
    {
        $output = [];
        $exitCode = 0;

        exec($this->command . ' 2>&1', $output, $exitCode);

        return [
            'command' => $this->command,
            'output' => $output,
            'exit_code' => $exitCode,
        ];
    }
}
