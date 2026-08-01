<?php

namespace App\Helpers\Widget;

use App\Helpers\Ai\DashboardAi;
use App\Helpers\DataSource\CodeTemplater;
use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use Throwable;

class ReviewWidgetsDashboard
{
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
            options: [
                'count_rows',
                'columns',
                'relations' => [
                    'column' => [
                        'type',
                        'nullable',
                        'key',
                    ],

                    'relation' => [
                        'table',
                    ],
                ],
            ]
        );

        $schemeStr = json_encode($scheme, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $errorsStr = json_encode($runResult['errors'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $data = [
            'instruction' => $widget->instruction,
            'code' => $fullCode,
            'errors' => $errorsStr,
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
            if (count($result['errors']) > 0) {
                $this->reGenerate($result['widget_id'], $result);
            }
        }
    }

    public function review($dataSource, $dashboard_widgets)
    {
        $widgetCodeRun = new WidgetCodeRun();
        $validator = new WidgetOutputValidator();
        $isError = false;
        $result = [];

        foreach ($dashboard_widgets as $widget) {
            $runResult = $widgetCodeRun->run(
                widget: $widget,
                dataSource: $dataSource
            );

            $rawOutput = $runResult['output'][0] ?? null;
            $type = $widget->widget->name ?? null;

            $decoded = null;
            $errors = [];

            if ($rawOutput === null) {
                $errors[] = "Пустой вывод скрипта";
            } elseif (!$type) {
                $errors[] = "Не удалось определить тип виджета (widget->widget->name)";
            } else {
                $decoded = json_decode($rawOutput, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errors[] = "Ошибка парсинга JSON: " . json_last_error_msg();
                } else {
                    $errors = $validator->validate($type, $decoded);
                }
            }

            if (count($errors) > 0) {
                $isError = true;
            }

            $result[] = [
                'widget_id'   => $widget->id ?? null,
                'widget_type' => $type,
                'command'     => $runResult['command'] ?? null,
                'exit_code'   => $runResult['exit_code'] ?? null,
                'output'      => $decoded ?? $rawOutput,
                'is_valid'    => empty($errors),
                'errors'      => $errors,
            ];
        }

        return ['result' => $result, 'isError' => $isError];
    }
}
