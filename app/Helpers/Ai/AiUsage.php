<?php

namespace App\Helpers\Ai;

use App\Models\AiUsageLog;
use App\Models\Company;
use Illuminate\Support\Facades\Log;

/**
 * Учёт расхода токенов и месячный лимит компании.
 */
class AiUsage
{
    /**
     * Записывает расход по текущему контексту.
     *
     * Вызывается из AIService после каждого ответа модели. Если контекст
     * не выставлен (например, вызов из tinker), запись пропускается — но это
     * повод поправить место вызова, поэтому пишем предупреждение в лог.
     */
    public static function record(int $tokens, ?string $model = null): void
    {
        if ($tokens <= 0) {
            return;
        }

        $context = AiUsageContext::current();

        if (!$context) {
            Log::warning('AiUsage: расход не записан — не выставлен контекст компании', [
                'tokens' => $tokens,
                'model' => $model,
            ]);

            return;
        }

        try {
            AiUsageLog::create([
                'company_id' => $context['company_id'],
                'chat_id' => $context['chat_id'] ?? null,
                'message_id' => $context['message_id'] ?? null,
                'operation' => $context['operation'] ?? null,
                'model' => $model,
                'tokens' => $tokens,
            ]);
        } catch (\Throwable $e) {
            // Учёт не должен ронять основную работу: ответ модель уже дала,
            // терять его из-за проблемы с записью статистики нельзя.
            Log::error('AiUsage: не удалось записать расход', [
                'error' => $e->getMessage(),
                'tokens' => $tokens,
            ]);
        }
    }

    /** Израсходовано компанией за текущий месяц. */
    public static function usedThisMonth(int $companyId): int
    {
        return (int) AiUsageLog::query()
            ->where('company_id', $companyId)
            ->currentMonth()
            ->sum('tokens');
    }

    /**
     * Исчерпан ли месячный лимит.
     *
     * Лимит не задан — расход не ограничен: платформа не должна вставать
     * из-за того, что администратор не заполнил поле.
     */
    public static function limitReached(?Company $company): bool
    {
        if (!$company || !$company->ai_token_limit) {
            return false;
        }

        return self::usedThisMonth($company->id) >= $company->ai_token_limit;
    }

    /**
     * Сводка для интерфейса и для сообщений об ошибке.
     *
     * @return array{limit: ?int, used: int, remaining: ?int, percent: ?int, reached: bool, by_operation: array}
     */
    public static function summary(Company $company): array
    {
        $used = self::usedThisMonth($company->id);
        $limit = $company->ai_token_limit;

        $byOperation = AiUsageLog::query()
            ->where('company_id', $company->id)
            ->currentMonth()
            ->selectRaw('operation, SUM(tokens) as tokens')
            ->groupBy('operation')
            ->orderByDesc('tokens')
            ->get()
            ->map(fn ($row) => [
                'operation' => $row->operation ?: 'прочее',
                'tokens' => (int) $row->tokens,
            ])
            ->all();

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit ? max(0, $limit - $used) : null,
            'percent' => $limit ? min(100, (int) round($used / $limit * 100)) : null,
            'reached' => $limit ? $used >= $limit : false,
            'period_start' => now()->startOfMonth()->toDateString(),
            'by_operation' => $byOperation,
        ];
    }
}
