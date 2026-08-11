<?php

namespace App\Helpers\Chat;

use App\Helpers\Ai\AiUsageContext;
use App\Helpers\Ai\DashboardSuggestionAi;
use App\Helpers\DataSource\DataSourceGrouping;
use App\Models\DashboardSuggestion;
use App\Models\DataSource;
use App\Models\Widget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Готовит варианты дашбордов для источника данных.
 *
 * Порядок работы:
 *   1. Уже есть сохранённые варианты — отдаём их, ничего не считаем.
 *      Варианты привязаны к источнику, поэтому второй чат на той же базе
 *      получает их бесплатно.
 *   2. Нет группировки таблиц — запускаем её (это тот же DataSourceGrouping,
 *      который потом использует генератор дашбордов, так что работа не
 *      пропадает: первый дашборд построится быстрее).
 *   3. Просим модель предложить темы и сохраняем результат.
 *
 * Ошибка на любом шаге НЕ должна ронять создание чата: пустой список
 * вариантов — приемлемая деградация, чат остаётся рабочим.
 */
class DashboardSuggestionGenerator
{
    public function __construct(private DataSource $dataSource)
    {
    }

    /**
     * @param bool $force Пересобрать варианты, даже если они уже сохранены.
     *
     * @return Collection<int, DashboardSuggestion>
     */
    public function handle(bool $force = false): Collection
    {
        if (!$force) {
            $existing = $this->stored();

            if ($existing->isNotEmpty()) {
                return $existing;
            }
        }

        // Первый чат на источнике тянет за собой группировку всей схемы плюс
        // запрос к модели — стандартных 30 секунд PHP на это не хватает.
        set_time_limit(300);

        // Подбор вариантов и возможная группировка идут за счёт компании —
        // записываем расход на неё.
        AiUsageContext::set($this->dataSource->company_id, null, null, 'suggestions');

        try {
            $groups = $this->resolveGroups();

            if (empty($groups)) {
                Log::warning('DashboardSuggestionGenerator: группы таблиц пусты', [
                    'data_source_id' => $this->dataSource->id,
                ]);

                return $this->stored();
            }

            $result = (new DashboardSuggestionAi())->generate(
                groups: $groups,
                widgetTypes: $this->widgetTypes(),
                sourceName: $this->dataSource->name
            );

            if (empty($result['suggestions'])) {
                return $this->stored();
            }

            return $this->save($result['suggestions']);

        } catch (\Throwable $e) {
            Log::error('DashboardSuggestionGenerator: не удалось подготовить варианты', [
                'data_source_id' => $this->dataSource->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->stored();
        } finally {
            AiUsageContext::clear();
        }
    }

    /**
     * Группы таблиц источника: берём готовые, а если их нет — считаем и
     * сохраняем, чтобы генератор дашбордов потом не считал заново.
     */
    private function resolveGroups(): array
    {
        $grouping = new DataSourceGrouping($this->dataSource->id);

        if (!$grouping->load()) {
            $grouping->handle();
            $grouping->save();
        }

        return $grouping->getGroups();
    }

    /**
     * Только те типы виджетов, что реально готовы к использованию, — тот же
     * фильтр, что у генераторов. Иначе модель предложит дашборд из виджетов,
     * которые платформа построить не сможет.
     */
    private function widgetTypes(): array
    {
        return Widget::query()
            ->where('is_ai_selectable', true)
            ->get(['name', 'description'])
            ->map(fn (Widget $widget) => [
                'name' => $widget->name,
                'description' => $widget->description,
            ])
            ->all();
    }

    /** @return Collection<int, DashboardSuggestion> */
    private function stored(): Collection
    {
        return DashboardSuggestion::query()
            ->where('data_source_id', $this->dataSource->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param array<int, array{title: string, prompt: string, description: string}> $suggestions
     *
     * @return Collection<int, DashboardSuggestion>
     */
    private function save(array $suggestions): Collection
    {
        DB::transaction(function () use ($suggestions) {
            DashboardSuggestion::query()
                ->where('data_source_id', $this->dataSource->id)
                ->delete();

            foreach ($suggestions as $position => $suggestion) {
                DashboardSuggestion::create([
                    'data_source_id' => $this->dataSource->id,
                    'title' => $suggestion['title'],
                    'prompt' => $suggestion['prompt'],
                    'description' => $suggestion['description'] ?: null,
                    'position' => $position,
                ]);
            }
        });

        Log::info('DashboardSuggestionGenerator: варианты сохранены', [
            'data_source_id' => $this->dataSource->id,
            'count' => count($suggestions),
        ]);

        return $this->stored();
    }
}
