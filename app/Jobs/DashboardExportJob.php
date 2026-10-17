<?php

namespace App\Jobs;

use App\Models\Dashboard;
use App\Models\DashboardExport;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Выгрузка дашборда в PDF или Word «как на экране».
 *
 * Страницу дашборда (/company/export/dashboard/{id}, без шапки и элементов
 * управления) открывает headless Chrome, который запускает scripts/export-dashboard.mjs.
 * Вход — коротким отдельным токеном пользователя, а не его рабочим: он живёт
 * несколько минут и удаляется сразу после съёмки.
 *
 *  - PDF — страницы A4, виджеты целиком (без разрыва посередине);
 *  - Word — каждый виджет снимается картинкой, scripts/export_docx.py собирает из них документ.
 */
class DashboardExportJob implements ShouldQueue
{
    use Queueable;

    // С запасом на ожидание общей блокировки Chrome (до 600 с) и саму съёмку.
    public $timeout = 900;

    public function __construct(public int $exportId)
    {
    }

    public function handle(): void
    {
        $export = DashboardExport::query()->find($this->exportId);

        if (!$export || $export->status === DashboardExport::READY) {
            return;
        }

        try {
            // Chrome тяжёлый: выгрузки идут по одной. Блокировка общая и для очереди, и для
            // отчётов по расписанию (ScheduledReportJob вызывает handle() напрямую).
            Cache::lock('dashboard-export-run', 900)->block(600, fn () => $this->render($export));
        } catch (LockTimeoutException) {
            $export->update([
                'status' => DashboardExport::FAILED,
                'error' => 'Сервер занят другими выгрузками — попробуйте чуть позже.',
            ]);
        }
    }

    private function render(DashboardExport $export): void
    {
        $export->update(['status' => DashboardExport::PROCESSING, 'error' => null]);

        $accessToken = null;
        $directory = null;

        try {
            $user = User::query()->findOrFail($export->user_id);
            $dashboard = Dashboard::query()
                ->where('company_id', $export->company_id)
                ->findOrFail($export->dashboard_id);

            $accessToken = $user->createToken('dashboard-export', ['*'], now()->addMinutes(10));

            $directory = storage_path(
                'app/company/'.$export->company_id.'/dashboards/'.$dashboard->id.
                '/exports/'.$export->id.'-'.Str::random(8)
            );
            File::ensureDirectoryExists($directory);

            $baseName = (Str::slug((string) $dashboard->name) ?: 'dashboard').'-'.now()->format('Y-m-d');
            $target = $directory.'/'.$baseName.'.'.$export->format;

            $capture = $this->capture($dashboard, $accessToken->plainTextToken, $export->format, $directory, $target);

            if ($export->format === 'docx') {
                $this->buildDocx($capture, $target);
            }

            if (!is_file($target) || filesize($target) === 0) {
                throw new RuntimeException('Файл не появился или пустой.');
            }

            $ttl = (int) config('exports.dashboard.ttl_days', 7);

            $export->update([
                'status' => DashboardExport::READY,
                'file_name' => basename($target),
                'path' => $target,
                'size' => filesize($target),
                'expires_at' => $ttl > 0 ? now()->addDays($ttl) : null,
            ]);

            // Картинки виджетов нужны были только для сборки Word.
            File::deleteDirectory($directory.'/parts');
        } catch (Throwable $e) {
            Log::error('DashboardExportJob: failed', [
                'export_id' => $export->id,
                'error' => $e->getMessage(),
            ]);

            $export->update([
                'status' => DashboardExport::FAILED,
                'error' => Str::limit($e->getMessage(), 500),
            ]);

            if ($directory) {
                File::deleteDirectory($directory);
            }
        } finally {
            $accessToken?->accessToken?->delete();
        }
    }

    /** Запускает Chrome. Возвращает JSON, который печатает скрипт. */
    private function capture(Dashboard $dashboard, string $token, string $format, string $directory, string $target): array
    {
        $config = config('exports.dashboard');

        $url = rtrim((string) $config['render_url'], '/').'/company/export/dashboard/'.$dashboard->id;

        $command = [
            $config['node'],
            base_path('scripts/export-dashboard.mjs'),
            '--url', $url,
            '--mode', $format === 'pdf' ? 'pdf' : 'parts',
            '--out', $format === 'pdf' ? $target : $directory.'/parts',
            '--width', (string) $config['width'],
            '--landscape', $config['pdf_landscape'] ? '1' : '0',
            '--timeout', (string) max(30, (int) $config['timeout'] - 30),
        ];

        if (!empty($config['chrome_path'])) {
            $command[] = '--chrome';
            $command[] = $config['chrome_path'];
        }

        // Токен — через окружение: аргументы команды видны в списке процессов.
        // HOME — во временную папку: у www-data (воркер очереди) домашний каталог недоступен
        // для записи, а Chrome кладёт туда профиль и crash-дампы.
        $process = new Process($command, base_path(), [
            'EXPORT_ACCESS_TOKEN' => $token,
            'HOME' => sys_get_temp_dir(),
        ]);
        $process->setTimeout((int) $config['timeout']);
        $process->run();

        $result = $this->lastJson($process->getOutput());

        if (!$process->isSuccessful() || !($result['ok'] ?? false)) {
            $reason = $result['error'] ?? trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new RuntimeException(sprintf(
                'Не удалось снять дашборд (код %s): %s',
                $process->getExitCode() ?? 'нет',
                Str::limit((string) $reason, 300)
            ));
        }

        return $result;
    }

    private function buildDocx(array $capture, string $target): void
    {
        $python = base_path('venv/bin/python');

        if (!file_exists($python)) {
            $python = 'python3';
        }

        $spec = $capture['parts_dir'].'/spec.json';

        file_put_contents($spec, json_encode([
            'title' => $capture['title'] ?? '',
            'subtitle' => $capture['subtitle'] ?? '',
            'parts' => $capture['parts'] ?? [],
        ], JSON_UNESCAPED_UNICODE));

        $process = new Process([$python, base_path('scripts/export_docx.py'), $spec, $target], base_path());
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Не удалось собрать Word: '.Str::limit(trim($process->getErrorOutput() ?: $process->getOutput()), 300));
        }
    }

    private function lastJson(string $output): array
    {
        // Строки делим по \n, а не по \R: без флага /u шаблон \R режет и по байту 0x85,
        // который входит в русскую «х» (D1 85), — и JSON с такими буквами ломался.
        foreach (array_reverse(explode("\n", trim($output))) as $line) {
            $line = trim($line);

            if ($line !== '' && $line[0] === '{') {
                $decoded = json_decode($line, true);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }
}
