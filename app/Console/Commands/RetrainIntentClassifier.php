<?php

namespace App\Console\Commands;

use App\Helpers\Ai\IntentClassifier;
use App\Helpers\PythonRunner;
use App\Models\IntentSample;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RetrainIntentClassifier extends Command
{
    protected $signature = 'intents:retrain
        {--force : Переобучить, даже если новых примеров мало}
        {--dry-run : Обучить и показать метрики, но модель не заменять}';

    protected $description = 'Дообучает классификатор намерений на примерах, размеченных языковой моделью';

    private const LOCK_KEY = 'intents:retrain';

    private string $directory;

    public function handle(): int
    {
        $this->directory = base_path('ml/intents');

        if (!is_file($this->directory.'/train.py')) {
            $this->error("Не найден скрипт обучения: {$this->directory}/train.py");

            return self::FAILURE;
        }

        $lock = Cache::lock(self::LOCK_KEY, 3600);

        if (!$lock->get()) {
            $this->warn('Переобучение уже идёт — пропускаю.');

            return self::SUCCESS;
        }

        try {
            return $this->retrain();
        } catch (Throwable $e) {
            Log::error('IntentClassifier: переобучение упало: '.$e->getMessage());
            $this->error('Ошибка: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function retrain(): int
    {
        $this->showQueue();

        $fresh = IntentSample::query()->usable()->where('used_in_training', false)->count();
        $minimum = (int) config('intents.learning.min_samples', 50);

        if ($fresh < $minimum && !$this->option('force')) {
            $this->info("Новых подтверждённых примеров {$fresh} из {$minimum} — переобучение пропущено (--force обходит).");

            return self::SUCCESS;
        }

        $lastId = (int) IntentSample::query()->usable()->max('id');

        $feedback = $this->directory.'/feedback.csv';
        $exported = $this->exportSamples($feedback, $lastId);

        if ($exported === 0) {
            $this->info('Подтверждённых примеров нет — дообучать не на чем.');

            return self::SUCCESS;
        }

        $this->line("Выгружено примеров: {$exported} (по id {$lastId} включительно)");

        $candidate = $this->directory.'/model.candidate.json';
        $candidateReport = $this->directory.'/model.candidate.report.json';

        File::delete([$candidate, $candidateReport]);

        $this->line('Обучение...');

        $result = (new PythonRunner(
            $this->directory.'/train.py',
            [
                '--extra' => $feedback,
                '--out' => $candidate,
                '--report' => $candidateReport,
            ],
            timeoutSeconds: (int) config('intents.learning.timeout', 900)
        ))->run();

        if (($result['exit_code'] ?? 1) !== 0 || !is_file($candidate) || !is_file($candidateReport)) {
            $this->error('Обучение не удалось. Последние строки вывода:');
            $this->line(implode("\n", array_slice($result['output'] ?? [], -15)));

            Log::error('IntentClassifier: обучение не удалось', [
                'exit_code' => $result['exit_code'] ?? null,
            ]);

            return self::FAILURE;
        }

        $new = $this->report($candidateReport);
        $baseline = $this->baseline();

        $this->compareTable($baseline, $this->report($this->directory.'/model.report.json'), $new);

        if ($this->option('dry-run')) {
            $this->info("Пробный запуск: модель не заменена, кандидат в {$candidate}");

            return self::SUCCESS;
        }

        if (!$this->passesBaseline($baseline, $new)) {
            return self::FAILURE;
        }

        $this->promote($candidate, $candidateReport, $lastId, $new);

        return self::SUCCESS;
    }

    private function showQueue(): void
    {
        $counts = IntentSample::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $this->line(sprintf(
            'Примеры: подтверждено %d, ждут исхода %d, отклонено исходом %d',
            $counts['confirmed'] ?? 0,
            $counts['pending'] ?? 0,
            $counts['rejected'] ?? 0
        ));
    }

    private function exportSamples(string $path, int $lastId): int
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new RuntimeException("Не удалось создать {$path}");
        }

        $count = 0;

        try {
            fputcsv($handle, ['text', 'context', 'label']);

            IntentSample::query()
                ->usable()
                ->where('id', '<=', $lastId)
                ->orderBy('id')
                ->chunk(500, function ($samples) use ($handle, &$count) {
                    foreach ($samples as $sample) {
                        fputcsv($handle, [$sample->text, (string) $sample->context, $sample->label]);
                        $count++;
                    }
                });
        } finally {
            fclose($handle);
        }

        return $count;
    }

    private function baseline(): ?array
    {
        $path = $this->directory.'/model.baseline.json';
        $baseline = $this->report($path);
        $current = $this->report($this->directory.'/model.report.json');

        if ($baseline === null) {
            return $current;
        }

        $baselineHash = $baseline['test_hash'] ?? null;
        $currentHash = $this->testHash();

        if ($baselineHash && $currentHash && $baselineHash !== $currentHash) {
            $this->warn('Отложенный набор изменился — прежняя базовая линия несопоставима, беру текущую модель.');

            File::delete($path);

            return $current;
        }

        return $baseline;
    }

    private function testHash(): ?string
    {
        $path = $this->directory.'/test.csv';

        return is_file($path) ? substr(hash_file('sha256', $path), 0, 16) : null;
    }

    private function passesBaseline(?array $baseline, ?array $new): bool
    {
        if ($baseline === null) {
            return true;
        }

        $drop = (float) ($baseline['accuracy'] ?? 0) - (float) ($new['accuracy'] ?? 0);
        $allowed = (float) config('intents.learning.max_accuracy_drop', 0.005);

        if ($drop <= $allowed) {
            return true;
        }

        $this->error(sprintf(
            'Точность ниже базовой линии на %.3f при допуске %.3f — модель НЕ заменена.',
            $drop,
            $allowed
        ));
        $this->line('Кандидат оставлен для разбора: '.$this->directory.'/model.candidate.json');

        Log::warning('IntentClassifier: переобучение отклонено по просадке качества', [
            'baseline' => $baseline['accuracy'] ?? null,
            'candidate' => $new['accuracy'] ?? null,
        ]);

        return false;
    }

    private function promote(string $candidate, string $candidateReport, int $lastId, ?array $new): void
    {
        File::move($candidate, $this->directory.'/model.json');
        File::move($candidateReport, $this->directory.'/model.report.json');

        if (!is_file($this->directory.'/model.baseline.json')) {
            File::copy($this->directory.'/model.report.json', $this->directory.'/model.baseline.json');
            $this->line('Базовая линия качества зафиксирована.');
        }

        $marked = IntentSample::query()
            ->usable()
            ->where('id', '<=', $lastId)
            ->where('used_in_training', false)
            ->update(['used_in_training' => true]);

        IntentClassifier::flush();

        $this->info(sprintf(
            'Модель обновлена: точность %.3f, обучающих примеров %d (живых %d), помечено использованными %d',
            (float) ($new['accuracy'] ?? 0),
            (int) ($new['train_size'] ?? 0),
            (int) ($new['live_samples'] ?? 0),
            $marked
        ));

        Log::info('IntentClassifier: модель переобучена', [
            'accuracy' => $new['accuracy'] ?? null,
            'train_size' => $new['train_size'] ?? null,
            'live_samples' => $new['live_samples'] ?? null,
            'marked_used' => $marked,
        ]);
    }

    private function compareTable(?array $baseline, ?array $current, ?array $new): void
    {
        $this->table(
            ['', 'базовая', 'текущая', 'новая'],
            [
                [
                    'точность на отложенном',
                    $this->format($baseline['accuracy'] ?? null),
                    $this->format($current['accuracy'] ?? null),
                    $this->format($new['accuracy'] ?? null),
                ],
                [
                    'macro-F1',
                    $this->format($baseline['macro_f1'] ?? null),
                    $this->format($current['macro_f1'] ?? null),
                    $this->format($new['macro_f1'] ?? null),
                ],
                [
                    'примеров в обучении',
                    $baseline['train_size'] ?? '—',
                    $current['train_size'] ?? '—',
                    $new['train_size'] ?? '—',
                ],
                [
                    'из них живых',
                    $baseline['live_samples'] ?? '—',
                    $current['live_samples'] ?? '—',
                    $new['live_samples'] ?? '—',
                ],
            ]
        );
    }

    private function report(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function format(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 3);
    }
}
