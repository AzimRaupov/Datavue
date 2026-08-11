<?php

/**
 * Проверка хостинга под DataVue.
 *
 * Загрузите этот файл на хост и запустите:
 *
 *     php check-host.php
 *
 * Если консоли нет — откройте его в браузере, вывод будет тот же.
 *
 * Скрипт ничего не меняет и не устанавливает: только проверяет и печатает
 * заключение. Единственное, что он создаёт, — временный каталог для пробы
 * venv, который тут же удаляет.
 */

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

const OK = '  [ДА]   ';
const NO = '  [НЕТ]  ';
const WARN = '  [!]    ';

$verdict = ['blockers' => [], 'warnings' => []];

function section(string $title): void
{
    echo "\n".str_repeat('─', 62)."\n{$title}\n".str_repeat('─', 62)."\n";
}

function line(string $mark, string $text, string $detail = ''): void
{
    echo $mark.$text.($detail !== '' ? "  —  {$detail}" : '')."\n";
}

/** Запускает команду в обход отключённых функций, если это возможно. */
function runCommand(string $command): ?array
{
    $command .= ' 2>&1';

    if (function_exists('exec')) {
        $output = [];
        $code = 1;
        @exec($command, $output, $code);

        return ['output' => implode("\n", $output), 'code' => $code];
    }

    if (function_exists('shell_exec')) {
        $out = @shell_exec($command);

        return $out === null ? null : ['output' => trim($out), 'code' => 0];
    }

    if (function_exists('proc_open')) {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return null;
        }

        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['output' => trim($out), 'code' => $code];
    }

    return null;
}

// ─────────────────────────────────────────────────────────────── PHP

section('PHP');

echo "  версия: ".PHP_VERSION."   SAPI: ".PHP_SAPI."\n";

if (version_compare(PHP_VERSION, '8.3', '>=')) {
    line(OK, 'версия подходит (нужен 8.3+)');
} else {
    line(NO, 'нужен PHP 8.3 или новее');
    $verdict['blockers'][] = 'PHP старее 8.3';
}

$disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));

echo "  отключённые функции: ".($disabled ? implode(', ', $disabled) : 'нет')."\n";

foreach (['exec', 'shell_exec', 'proc_open'] as $function) {
    function_exists($function)
        ? line(OK, "функция {$function}() доступна")
        : line(NO, "функция {$function}() отключена");
}

// Главный вопрос: можно ли вообще запустить внешнюю программу.
$probe = runCommand('echo datavue');

if ($probe !== null && str_contains($probe['output'], 'datavue')) {
    line(OK, 'запуск внешних программ работает');
} else {
    line(NO, 'запуск внешних программ НЕ работает');
    $verdict['blockers'][] = 'нельзя запускать внешние программы — не сработают виджеты и выгрузки';
}

foreach (['pdo_mysql', 'mbstring', 'zip', 'gd', 'curl', 'openssl', 'fileinfo'] as $extension) {
    extension_loaded($extension)
        ? line(OK, "расширение {$extension}")
        : line(NO, "расширение {$extension} отсутствует");

    if (!extension_loaded($extension) && in_array($extension, ['pdo_mysql', 'mbstring', 'curl', 'openssl'], true)) {
        $verdict['blockers'][] = "нет расширения {$extension}";
    }
}

echo "  memory_limit: ".ini_get('memory_limit')."   max_execution_time: ".ini_get('max_execution_time')."\n";

// ─────────────────────────────────────────────────────────── Python

section('PYTHON');

$python = null;

foreach (['python3', 'python3.12', 'python3.11', 'python3.10', 'python'] as $candidate) {
    $result = runCommand("{$candidate} --version");

    if ($result !== null && $result['code'] === 0 && stripos($result['output'], 'python') !== false) {
        $python = $candidate;
        line(OK, "найден {$candidate}", trim($result['output']));
        break;
    }
}

if ($python === null) {
    line(NO, 'интерпретатор Python не найден');
    $verdict['blockers'][] = 'нет Python — не заработают виджеты, выгрузки и импорт файлов';
} else {
    $pip = runCommand("{$python} -m pip --version");

    if ($pip !== null && $pip['code'] === 0) {
        line(OK, 'pip доступен', trim(explode(' from ', $pip['output'])[0] ?? ''));
    } else {
        line(NO, 'pip недоступен — библиотеки не поставить');
        $verdict['blockers'][] = 'нет pip: pandas и остальные библиотеки установить нечем';
    }

    // Уже установленные библиотеки: вдруг хостинг их даёт.
    $needed = ['pandas', 'numpy', 'sklearn', 'openpyxl', 'reportlab', 'docx', 'mysql.connector'];
    $missing = [];

    foreach ($needed as $module) {
        $check = runCommand("{$python} -c \"import {$module}\"");

        if ($check !== null && $check['code'] === 0) {
            line(OK, "модуль {$module} уже установлен");
        } else {
            $missing[] = $module;
        }
    }

    if ($missing) {
        line(WARN, 'не установлены: '.implode(', ', $missing), 'их нужно поставить через pip');
    }

    // Проба venv: без него библиотеки ставить некуда, если нет прав на系统ные.
    $venvPath = sys_get_temp_dir().'/datavue_venv_probe_'.getmypid();
    $venv = runCommand("{$python} -m venv ".escapeshellarg($venvPath));

    if ($venv !== null && $venv['code'] === 0 && is_dir($venvPath)) {
        line(OK, 'создание venv работает');
    } else {
        line(NO, 'venv создать не удалось', trim((string) ($venv['output'] ?? '')));
        $verdict['warnings'][] = 'нет venv — придётся ставить библиотеки с ключом --user';
    }

    if (is_dir($venvPath)) {
        runCommand('rm -rf '.escapeshellarg($venvPath));
    }
}

// ───────────────────────────────────────────────────────── Ресурсы

section('РЕСУРСЫ');

$home = getenv('HOME') ?: __DIR__;
$free = @disk_free_space($home);

if ($free !== false) {
    $gb = $free / 1024 / 1024 / 1024;
    printf("  свободно на диске: %.1f ГБ\n", $gb);

    if ($gb < 1.5) {
        line(WARN, 'меньше 1.5 ГБ', 'библиотеки Python занимают около 540 МБ');
        $verdict['warnings'][] = 'мало места на диске';
    } else {
        line(OK, 'места достаточно');
    }
}

$quota = runCommand('quota -s 2>/dev/null || df -h '.escapeshellarg($home));

if ($quota !== null && trim($quota['output']) !== '') {
    echo "  квота:\n    ".str_replace("\n", "\n    ", trim($quota['output']))."\n";
}

// ────────────────────────────────────────────────────────── Сеть

section('СЕТЬ');

$targets = [
    'api.openai.com:443' => 'обращения к языковой модели',
    'pypi.org:443' => 'установка библиотек Python',
];

foreach ($targets as $target => $why) {
    [$host, $port] = explode(':', $target);
    $socket = @fsockopen($host, (int) $port, $errno, $errstr, 5);

    if ($socket) {
        fclose($socket);
        line(OK, "исходящее соединение {$target}", $why);
    } else {
        line(NO, "нет соединения с {$target}", $why);
        $verdict['blockers'][] = "закрыт исход на {$target} — {$why}";
    }
}

// Порт чужой базы: проверяем на публичном хосте, отвечающем на 3306.
$socket = @fsockopen('db4free.net', 3306, $errno, $errstr, 5);

if ($socket) {
    fclose($socket);
    line(OK, 'исходящий 3306 открыт', 'подключение к внешним MySQL пользователя');
} else {
    line(WARN, 'исходящий 3306 закрыт', 'внешние базы пользователей подключить не выйдет');
    $verdict['warnings'][] = 'закрыт исходящий 3306 — только локальные базы';
}

// ────────────────────────────────────────────────────── Заключение

section('ЗАКЛЮЧЕНИЕ');

if (!$verdict['blockers']) {
    echo "  Проект заработает без переделок.\n";
} else {
    echo "  Мешает следующее:\n";

    foreach ($verdict['blockers'] as $item) {
        echo "    · {$item}\n";
    }
}

if ($verdict['warnings']) {
    echo "\n  Замечания:\n";

    foreach ($verdict['warnings'] as $item) {
        echo "    · {$item}\n";
    }
}

echo "\n  Отдельно проверьте руками:\n";
echo "    · есть ли планировщик (cron) — нужен для очереди задач;\n";
echo "    · не убивает ли хостинг процессы дольше минуты: генерация\n";
echo "      дашборда идёт 2–10 минут;\n";
echo "    · разрешены ли долгие фоновые задачи в правилах тарифа.\n\n";
