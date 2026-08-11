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

/**
 * Дообучение классификатора намерений на решениях языковой модели.
 *
 * Замыкает цикл: локальная модель делегирует спорные фразы GPT, его ответ
 * сохраняется обучающим примером, накопленные примеры возвращаются в обучение.
 * Это активное обучение с отбором по неуверенности — учим ровно на том, чего
 * модель не знает, а не на том, что она и так предсказывает верно.
 *
 * Порядок шагов и почему он именно такой:
 *
 *   1. Замок. Два одновременных переобучения перезаписали бы файлы друг друга.
 *   2. Отбор: только примеры, подтверждённые исходом (IntentSample::confirm).
 *   3. Граница выборки по id. Обучение идёт минуты, за это время появятся новые
 *      примеры; пометить их использованными значило бы потерять — в обучение
 *      они не попали, а к следующему разу уже считались бы старыми.
 *   4. Обучение в кандидата, а не поверх рабочей модели.
 *   5. Сверка с базовой линией — зафиксированной, а не с текущей моделью:
 *      иначе просадка копится по чуть-чуть и проходит проверку каждый раз.
 *   6. Замена файлов и пометка ровно той выборки, что участвовала в обучении.
 *
 * Сбой на любом шаге оставляет систему в прежнем рабочем состоянии: модель
 * заменяется последним действием и только после успешной проверки.
 */
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

        // Замок на всё время работы: обучение занимает минуты, а параллельный
        // запуск писал бы в те же файлы кандидата.
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

        // Граница выборки. Всё, что появится позже, в этот прогон не попадёт —
        // и использованным помечено не будет.
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

        // Файлы прошлой неудачной попытки: без удаления можно принять
        // за успех чужой старый результат.
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

        // Обучение обязано оставить оба файла: и веса, и отчёт. Один без
        // другого означает обрыв на середине.
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

    /**
     * Сколько примеров ждёт подтверждения исходом и сколько исход отклонил.
     *
     * Отклонённые — это места, где ошибся сам учитель: маршрутизатор счёл
     * сообщение командой, а исполнитель не нашёл, что делать. Полезно видеть
     * даже тогда, когда переобучение не запускается.
     */
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

    /**
     * Выгружает подтверждённые примеры в CSV того же формата, что и train.csv.
     *
     * Берём все, а не только новые: обучение идёт с нуля каждый раз, и
     * исключение старых означало бы забывание уже выученного.
     *
     * Отсев фраз, совпадающих с отложенным набором, здесь не делается
     * намеренно — он живёт в train.py, рядом с самим набором, чтобы правило
     * было в одном месте.
     */
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

    /**
     * Базовая линия качества — планка, ниже которой опускаться нельзя.
     *
     * Зафиксирована один раз и не двигается: сравнение с текущей моделью
     * разрешало терять по чуть-чуть на каждом прогоне, и десять прогонов
     * подряд честно «проходили проверку», уводя качество далеко вниз.
     *
     * Отдельно проверяется отпечаток отложенного набора. Его нужно пополнять
     * живыми фразами, а точности, посчитанные на разных наборах, сравнивать
     * нельзя — при подмене базовая линия берётся заново.
     *
     * @return array<string, mixed>|null
     */
    private function baseline(): ?array
    {
        $path = $this->directory.'/model.baseline.json';
        $baseline = $this->report($path);
        $current = $this->report($this->directory.'/model.report.json');

        if ($baseline === null) {
            return $current;
        }

        // Отпечаток считаем сами по файлу, а не берём из отчёта текущей модели:
        // отчёт мог быть создан версией скрипта без этого поля, и проверка
        // молча не срабатывала бы — что и случилось при первой её обкатке.
        $baselineHash = $baseline['test_hash'] ?? null;
        $currentHash = $this->testHash();

        if ($baselineHash && $currentHash && $baselineHash !== $currentHash) {
            $this->warn('Отложенный набор изменился — прежняя базовая линия несопоставима, беру текущую модель.');

            File::delete($path);

            return $current;
        }

        return $baseline;
    }

    /**
     * Отпечаток отложенного набора. Совпадает с тем, что пишет train.py.
     */
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

        // Просадка означает, что среди новых меток слишком много неверных.
        // Оставляем прежнюю модель и кандидата — по нему видно, что пошло не так.
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

    /**
     * Замена рабочей модели кандидатом.
     *
     * Порядок важен: сначала веса, затем отчёт, и только потом пометка примеров.
     * Если что-то упадёт посередине, примеры останутся непомеченными и уйдут
     * в следующий прогон — это безопаснее, чем потерять их навсегда.
     */
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

        // Долгоживущие процессы (воркеры очереди) держат модель в памяти.
        // Здесь сбрасываем свою копию, у остальных её подхватит проверка
        // времени правки файла — см. IntentClassifier::model().
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

    /**
     * @return array<string, mixed>|null
     */
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
