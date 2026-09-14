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

class DashboardSuggestionGenerator
{
    public function __construct(private DataSource $dataSource)
    {
    }

    public function handle(bool $force = false): Collection
    {
        if (!$force) {
            $existing = $this->stored();

            if ($existing->isNotEmpty()) {
                return $existing;
            }
        }

        set_time_limit(300);

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

    private function resolveGroups(): array
    {
        $grouping = new DataSourceGrouping($this->dataSource->id);

        if (!$grouping->load()) {
            $grouping->handle();
            $grouping->save();
        }

        return $grouping->getGroups();
    }

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

    private function stored(): Collection
    {
        return DashboardSuggestion::query()
            ->where('data_source_id', $this->dataSource->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

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
