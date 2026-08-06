<?php

namespace App\Helpers\Widget;

use App\Helpers\Ai\DashboardAi;
use App\Helpers\DataSource\CodeTemplater;
use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\DataSource\SchemaOptions;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use Throwable;

class ReviewWidgetsDashboard
{
    private const MAX_FIX_ATTEMPTS = 2;

    public $dashboard_widgets = null;
    public $dashboard = null;
    public $dataSource;
    public $codeTemplate;
    public $dashboardAi;
    public $connectionProviderRouter;

    public function __construct(?int $dashboardId = null, ?int $dataSourceId = null)
    {
        if ($dashboardId) {
            $this->dashboard = Dashboard::find($dashboardId);

            $this->dashboard_widgets = DashboardWidget::query()
                ->where('dashboard_id', $dashboardId)
                ->with('widget')
                ->get();
        }
        if ($dataSourceId) {
            $this->dataSource = DataSource::find($dataSourceId);
            $this->codeTemplate = new CodeTemplater($this->dataSource->id);
            $this->dashboardAi = new DashboardAi($this->dataSource);
            $this->connectionProviderRouter = new ConnectionProviderRouter($this->dataSource->id);
        }
    }

    /**
     * Возвращает статус ВЫПОЛНЕНИЯ процесса проверки/исправления,
     * а не результат валидации структуры виджетов.
     *
     * @return array{errors: bool, message?: string}
     */
    public function handle(): array
    {
        try {
            $review = $this->review($this->dataSource, $this->dashboard_widgets);
            if ($review['isError']) {
                $this->startReGenerate($review['result']);
            }

            return [
                'errors' => false,
                'message' => null,

            ];
        } catch (Throwable $e) {
            return [
                'errors'  => true,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function reGenerate($widgetId, $runResult)
    {
        $widget = DashboardWidget::query()->with('widget')->find($widgetId);

        $fullCode = $this->codeTemplate->getLibraries() . "\n";
        $fullCode .= $this->codeTemplate->getQueryTemplate() . "\n";
        $fullCode .= file_get_contents($widget->code_path) . "\n";
        $fullCode .= $this->codeTemplate->getFooter();

        $scheme = $this->connectionProviderRouter->getSchema(
            tables: $widget->tables,
            options: SchemaOptions::basic()
        );

        $schemeStr = json_encode($scheme, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $errorsStr = json_encode($runResult['errors'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $data = [
            'instruction' => $widget->instruction,
            'code' => $fullCode,
            'errors' => $errorsStr,
            'outIsArr' => is_array($runResult['output']),
            'output' => is_string($runResult['output'])
                ? $runResult['output']
                : null,
            'tables_scheme' => $schemeStr,
            'widget_scheme' => $widget->widget->scheme,
            'widget_scheme_description' => $widget->widget->scheme_description,
        ];

        $response = $this->dashboardAi->reViewErrorsWidget($data);

        if (!empty($response['content']['code_main'])) {
            file_put_contents($widget->code_path, $response['content']['code_main']);
        }

        return $response;
    }

    public function startReGenerate($resultsRun)
    {
        foreach ($resultsRun as $result) {
            if (empty($result['errors'])) {
                continue;
            }

            if (empty($result['has_code'])) {
                // Кода вообще нет (генерация не завершилась) — чинить через reGenerate() нечего,
                // это требует полной перегенерации, а не патча существующего кода.
                DashboardWidget::query()
                    ->where('id', $result['widget_id'])
                    ->update(['status' => 'failed']);

                continue;
            }

            $this->fixWidget($result['widget_id'], $result);
        }
    }

    /**
     * Пытается исправить виджет по результатам ошибок и заново прогоняет/валидирует код,
     * чтобы убедиться, что фикс реально сработал. Раньше reGenerate() вызывался один раз
     * "вслепую" — статус виджета никак не отражал, помог фикс или нет.
     */
    private function fixWidget(int $widgetId, array $runResult, int $attempt = 1): void
    {
        $this->reGenerate($widgetId, $runResult);

        $widget = DashboardWidget::query()->with('widget')->find($widgetId);

        if (!$widget) {
            return;
        }

        $recheck = $this->runAndValidateWidget($widget);

        if ($recheck['is_valid']) {
            $widget->status = 'active';
            $widget->save();

            return;
        }

        if ($attempt < self::MAX_FIX_ATTEMPTS) {
            $this->fixWidget($widgetId, $recheck, $attempt + 1);

            return;
        }

        $widget->status = 'failed';
        $widget->save();
    }

    public function review($dataSource, $dashboard_widgets)
    {
        $isError = false;
        $result = [];

        foreach ($dashboard_widgets as $widget) {
            $entry = $this->runAndValidateWidget($widget);

            if (!$entry['is_valid']) {
                $isError = true;
            }

            $result[] = $entry;
        }

        return ['result' => $result, 'isError' => $isError];
    }

    /**
     * Запускает python-код виджета и валидирует вывод. Вынесено из review() в отдельный
     * метод, чтобы его же можно было использовать при повторной проверке после fixWidget().
     */
    private function runAndValidateWidget(DashboardWidget $widget): array
    {
        $widgetType = $widget->widget->name ?? null;

        if (!$widget->code_path || !is_file($widget->code_path)) {
            return [
                'widget_id'   => $widget->id ?? null,
                'widget_type' => $widgetType,
                'command'     => null,
                'exit_code'   => null,
                'output'      => null,
                'is_valid'    => false,
                'errors'      => ['Код виджета не найден (генерация не завершилась)'],
                'has_code'    => false,
            ];
        }

        try {
            $runResult = (new WidgetCodeRun())->run(
                widget: $widget,
                dataSource: $this->dataSource
            );
        } catch (Throwable $e) {
            return [
                'widget_id'   => $widget->id ?? null,
                'widget_type' => $widgetType,
                'command'     => null,
                'exit_code'   => null,
                'output'      => null,
                'is_valid'    => false,
                'errors'      => [$e->getMessage()],
                'has_code'    => true,
            ];
        }

        $rawOutput = $runResult['output'] ?? null;

        if (is_array($rawOutput)) {
            $rawOutput = implode("\n", $rawOutput);
        }

        $decoded = null;
        $errors = [];

        if ($rawOutput === null) {
            $errors[] = "Пустой вывод скрипта";
        } elseif (!$widgetType) {
            $errors[] = "Не удалось определить тип виджета (widget->widget->name)";
        } else {
            $decoded = json_decode($rawOutput, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = "Ошибка парсинга JSON: " . json_last_error_msg();
            } else {
                $errors = (new WidgetOutputValidator())->validate($widgetType, $decoded);
            }
        }

        return [
            'widget_id'   => $widget->id ?? null,
            'widget_type' => $widgetType,
            'command'     => $runResult['command'] ?? null,
            'exit_code'   => $runResult['exit_code'] ?? null,
            'output'      => $decoded ?? $rawOutput,
            'is_valid'    => empty($errors),
            'errors'      => $errors,
            'has_code'    => true,
        ];
    }
}
