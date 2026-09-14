<?php

namespace App\Jobs;

use App\Events\DataSourceGroupingProgress;
use App\Helpers\Ai\AiUsageContext;
use App\Helpers\DataSource\DataSourceGrouping;
use App\Models\DataSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class DataSourceGroupingJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 1800;

    public function __construct(
        public int $dataSourceId,
        public bool $force = false
    ) {
    }

    public function handle(): void
    {
        $dataSource = DataSource::find($this->dataSourceId);

        if (!$dataSource) {
            return;
        }

        $this->publish($dataSource, 'in_progress', 'start', 'Подключаемся к источнику', 0, 3);

        AiUsageContext::set($dataSource->company_id, null, null, 'grouping');

        try {
            $grouping = new DataSourceGrouping($dataSource->id);

            $grouping->onProgress(
                function (string $stage, string $label, int $step, int $total) use ($dataSource) {
                    $this->publish($dataSource, 'in_progress', $stage, $label, $step, $total);
                }
            );

            if ($this->force || !$grouping->load()) {
                $grouping->handle();
                $grouping->save();
            }

            $this->publish(
                $dataSource,
                'completed',
                'done',
                'Готово',
                3,
                3,
                sprintf('Найдено групп: %d', count($grouping->getGroups()))
            );

        } catch (Throwable $e) {
            Log::error('DataSourceGroupingJob: группировка не удалась', [
                'data_source_id' => $this->dataSourceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->publish(
                $dataSource,
                'failed',
                'failed',
                'Не удалось сгруппировать таблицы',
                0,
                3,
                $e->getMessage()
            );

            throw $e;
        } finally {
            AiUsageContext::clear();
        }
    }

    private function publish(
        DataSource $dataSource,
        string $status,
        string $stage,
        string $label,
        int $step,
        int $total,
        ?string $message = null
    ): void {
        $dataSource->forceFill([
            'grouping_status' => $status,
            'grouping_stage' => $label,
            'grouping_message' => $message,
        ])->save();

        event(new DataSourceGroupingProgress(
            dataSource: $dataSource,
            stage: $stage,
            label: $label,
            step: $step,
            total: $total,
            status: $status,
            message: $message
        ));
    }
}
