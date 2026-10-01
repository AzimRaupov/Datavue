<?php

namespace App\Helpers\Ai;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class IntentClassifier
{
    public const CHAT = 'chat';
    public const DASHBOARD = 'dashboard';
    public const EXPORT = 'export';

    private static ?array $model = null;

    private static bool $loadFailed = false;

    private static ?int $loadedAt = null;

    public function predict(string $text, string $context = ''): ?array
    {
        if (!config('intents.enabled', true)) {
            return null;
        }

        $model = $this->model();

        if ($model === null || trim($text) === '') {
            return null;
        }

        try {
            return $this->compute($model, $text, $context);
        } catch (Throwable $e) {

            Log::warning('IntentClassifier: предсказание не выполнено', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public static function offerContext(?string $type, ?string $summary = null): string
    {
        $type = trim((string) $type);

        return $type === '' ? '' : 'offer_'.$type;
    }

    public static function contextFrom(?string $offerType, ?string $offerSummary, ?string $answer): string
    {
        $type = trim((string) $offerType);
        $answer = (string) $answer;

        if ($type !== '' && $type !== 'none') {
            return self::offerContext($type, $offerSummary);
        }

        if (self::looksLikeOffer($answer)) {

            $inferred = (new self())->inferOffer($answer);

            return $inferred !== null ? self::offerContext($inferred) : $answer;
        }

        return $type !== '' ? self::offerContext($type) : $answer;
    }

    public function inferOffer(string $answer): ?string
    {
        $model = $this->model();

        if ($model === null) {
            return null;
        }

        $tail = $this->answerTail($answer, (int) config('intents.infer_offer_chars', 220));

        if ($tail === '') {
            return null;
        }

        $prediction = $this->predict($tail);

        if ($prediction === null || $prediction['label'] === self::CHAT) {
            return null;
        }

        return $prediction['confidence'] >= (float) config('intents.infer_offer_threshold', 0.60)
            ? $prediction['label']
            : null;
    }

    private static function looksLikeOffer(string $answer): bool
    {
        $tail = trim(preg_replace('/\s+/u', ' ', $answer) ?? $answer);

        if ($tail === '') {
            return false;
        }

        return str_ends_with($tail, '?')
            || (bool) preg_match('/\b(могу|хотите|хочешь|применить|давайте|предлагаю|стоит ли)\b/iu', mb_substr($tail, -200, null, 'UTF-8'));
    }

    public function isLearnable(string $text, ?array $prediction = null): bool
    {
        if ($this->isUnintelligible($text, $prediction)) {
            return false;
        }

        $coverage = $prediction['coverage'] ?? $this->coverage($text);

        if ($coverage === null) {
            return true;
        }

        $words = count(preg_split('/\s+/u', trim($text)) ?: []);

        if ($words === 1 && mb_strlen(trim($text), 'UTF-8') >= 6) {
            return $coverage >= (float) config('intents.learning.single_word_min_coverage', 0.40);
        }

        return $coverage >= (float) config('intents.learning.min_coverage', 0.10);
    }

    public function isUnintelligible(string $text, ?array $prediction = null): bool
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $length = mb_strlen($normalized, 'UTF-8');

        if ($length === 0) {
            return true;
        }

        if (!preg_match('/\p{L}/u', $normalized)) {
            return $length >= (int) config('intents.unintelligible.min_length', 6);
        }

        if ($length < (int) config('intents.unintelligible.min_length', 12)) {
            return false;
        }

        $coverage = $prediction['coverage'] ?? $this->coverage($normalized);

        if ($coverage === null || $coverage >= (float) config('intents.unintelligible.max_coverage', 0.12)) {
            return false;
        }

        return $this->looksStructureless($normalized);
    }

    private function looksStructureless(string $text): bool
    {
        $words = preg_split('/\s+/u', $text) ?: [];
        $longest = 0;

        foreach ($words as $word) {
            $longest = max($longest, mb_strlen($word, 'UTF-8'));
        }

        if ($longest >= (int) config('intents.unintelligible.max_word_length', 12)) {
            return true;
        }

        $chars = mb_str_split($text, 1, 'UTF-8');
        $bigrams = [];

        for ($i = 0; $i + 1 < count($chars); $i++) {
            $bigrams[] = $chars[$i].$chars[$i + 1];
        }

        if (count($bigrams) < 4) {
            return false;
        }

        $diversity = count(array_unique($bigrams)) / count($bigrams);

        return $diversity < (float) config('intents.unintelligible.min_bigram_diversity', 0.5);
    }

    public function coverage(string $text): ?float
    {
        $model = $this->model();

        if ($model === null) {
            return null;
        }

        $total = 0;
        $known = 0;

        foreach ($this->ngrams($this->normalize($text), $model['ngram_min'], $model['ngram_max']) as $gram) {
            $total++;

            if (isset($model['vocabulary']['m:'.$gram])) {
                $known++;
            }
        }

        return $total > 0 ? $known / $total : 0.0;
    }

    public function isConfident(array $prediction, ?string $offeredType = null): bool
    {
        $base = (float) config('intents.threshold', 0.70);

        if ($offeredType !== null && $offeredType === $prediction['label']) {
            return $prediction['confidence'] >= $base;
        }

        $threshold = $prediction['label'] === self::DASHBOARD
            ? (float) config('intents.threshold_dashboard', 0.80)
            : $base;

        return $prediction['confidence'] >= $threshold;
    }

    public static function labelForTask(?string $taskName): ?string
    {
        return match ($taskName) {
            'response_in_chat' => self::CHAT,
            'generate_dashboard', 're_generate_dashboard' => self::DASHBOARD,
            'export_data' => self::EXPORT,
            default => null,
        };
    }

    public static function labels(): array
    {
        return [self::CHAT, self::DASHBOARD, self::EXPORT];
    }

    public function isAvailable(): bool
    {
        return $this->model() !== null;
    }

    public function info(): ?array
    {
        $model = $this->model();

        if ($model === null) {
            return null;
        }

        return [
            'created_at' => $model['created_at'] ?? null,
            'classes' => $model['classes'] ?? [],
            'features' => count($model['vocabulary'] ?? []),
            'threshold' => (float) config('intents.threshold', 0.70),
        ];
    }

    public function selfTest(): array
    {
        $model = $this->model();

        if ($model === null) {
            throw new RuntimeException('Модель классификатора недоступна');
        }

        $cases = $model['selftest'] ?? [];
        $worst = 0.0;

        foreach ($cases as $case) {
            $prediction = $this->compute($model, $case['text'], $case['context'] ?? '');

            foreach ($model['classes'] as $index => $class) {
                $worst = max($worst, abs(
                    $prediction['probabilities'][$class] - (float) $case['proba'][$index]
                ));
            }
        }

        return ['checked' => count($cases), 'max_deviation' => $worst];
    }

    public static function flush(): void
    {
        self::$model = null;
        self::$loadFailed = false;
        self::$loadedAt = null;
    }

    private function model(): ?array
    {
        $path = config('intents.model_path');

        if (self::$model !== null) {

            if ($path && is_file($path) && @filemtime($path) === self::$loadedAt) {
                return self::$model;
            }

            self::flush();
        } elseif (self::$loadFailed) {
            return null;
        }

        if (!$path || !is_file($path)) {
            self::$loadFailed = true;

            Log::info('IntentClassifier: модель не найдена, маршрутизация идёт через языковую модель', [
                'path' => $path,
            ]);

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded) || empty($decoded['vocabulary']) || empty($decoded['coef'])) {
            self::$loadFailed = true;

            Log::warning('IntentClassifier: файл модели повреждён', ['path' => $path]);

            return null;
        }

        self::$loadedAt = @filemtime($path) ?: null;

        return self::$model = $decoded;
    }

    private function compute(array $model, string $text, string $context = ''): array
    {
        $vocabulary = $model['vocabulary'];
        $idf = $model['idf'];

        $counts = [];
        $blocks = [];

        $totalGrams = 0;

        foreach ($this->features($model, $text, $context) as $gram) {
            $totalGrams++;

            if (isset($vocabulary[$gram])) {
                $index = $vocabulary[$gram];
                $counts[$index] = ($counts[$index] ?? 0) + 1;

                $blocks[$index] = $gram[0];
            }
        }

        $raw = [];
        $blockNorms = ['m' => 0.0, 'c' => 0.0];
        $knownGrams = array_sum($counts);

        foreach ($counts as $index => $count) {

            $tf = ($model['sublinear_tf'] ?? true) ? 1.0 + log($count) : (float) $count;
            $value = $tf * (float) $idf[$index];

            $raw[$index] = $value;
            $blockNorms[$blocks[$index]] += $value * $value;
        }

        $weight = (float) ($model['context_weight'] ?? 1.0);
        $vector = [];
        $norm = 0.0;

        foreach ($raw as $index => $value) {
            $block = $blocks[$index];
            $blockNorm = sqrt($blockNorms[$block]);

            if ($blockNorm <= 0.0) {
                continue;
            }

            $value = $value / $blockNorm * ($block === 'c' ? $weight : 1.0);

            $vector[$index] = $value;
            $norm += $value * $value;
        }

        $norm = sqrt($norm);

        if ($norm > 0.0) {
            foreach ($vector as $index => $value) {
                $vector[$index] = $value / $norm;
            }
        }

        $scores = [];

        foreach ($model['coef'] as $classIndex => $weights) {
            $score = (float) $model['intercept'][$classIndex];

            foreach ($vector as $index => $value) {
                $score += $value * (float) $weights[$index];
            }

            $scores[$classIndex] = $score;
        }

        $max = max($scores);
        $exponents = [];
        $total = 0.0;

        foreach ($scores as $classIndex => $score) {
            $value = exp($score - $max);
            $exponents[$classIndex] = $value;
            $total += $value;
        }

        $probabilities = [];
        $bestClass = null;
        $bestValue = -1.0;

        foreach ($exponents as $classIndex => $value) {
            $probability = $value / $total;
            $class = $model['classes'][$classIndex];
            $probabilities[$class] = $probability;

            if ($probability > $bestValue) {
                $bestValue = $probability;
                $bestClass = $class;
            }
        }

        return [
            'label' => $bestClass,
            'confidence' => $bestValue,
            'probabilities' => $probabilities,

            'coverage' => $totalGrams > 0 ? $knownGrams / $totalGrams : 0.0,
        ];
    }

    private function features(array $model, string $text, string $context = ''): \Generator
    {
        foreach ($this->ngrams($this->normalize($text), $model['ngram_min'], $model['ngram_max']) as $gram) {
            yield 'm:'.$gram;
        }

        $tail = $this->contextTail($context, (int) ($model['context_chars'] ?? 80));

        if ($tail === '') {
            return;
        }

        foreach ($this->ngrams($this->normalize($tail), $model['ngram_min'], $model['ngram_max']) as $gram) {
            yield 'c:'.$gram;
        }
    }

    private function answerTail(string $answer, int $limit): string
    {
        $text = preg_replace('/[*_`#>\[\]()|\-]+/u', ' ', $answer) ?? $answer;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }

        $tail = mb_substr($text, -$limit, null, 'UTF-8');
        $space = mb_strpos($tail, ' ', 0, 'UTF-8');

        return $space !== false ? mb_substr($tail, $space + 1, null, 'UTF-8') : $tail;
    }

    private function contextTail(string $answer, int $limit): string
    {
        $text = preg_replace('/[*_`#>\[\]()|\-]+/u', ' ', $answer) ?? $answer;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($text === '') {
            return '';
        }

        $sentences = array_values(array_filter(
            array_map('trim', preg_split('/[.!?…]+/u', $text) ?: []),
            fn ($part) => $part !== ''
        ));

        if ($sentences) {
            $text = end($sentences);
        }

        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }

        $tail = mb_substr($text, -$limit, null, 'UTF-8');

        $space = mb_strpos($tail, ' ', 0, 'UTF-8');

        return ($space !== false && $space < 20)
            ? mb_substr($tail, $space + 1, null, 'UTF-8')
            : $tail;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace('ё', 'е', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return ' '.trim($text).' ';
    }

    private function ngrams(string $text, int $min, int $max): \Generator
    {
        $chars = mb_str_split($text, 1, 'UTF-8');
        $length = count($chars);

        for ($n = $min; $n <= $max; $n++) {
            for ($i = 0; $i + $n <= $length; $i++) {
                yield implode('', array_slice($chars, $i, $n));
            }
        }
    }
}
