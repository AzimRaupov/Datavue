<?php

namespace App\Helpers\Widget;

use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\DataSource\SchemaOptions;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Последний шаг генерации: прогнать готовые виджеты и починить сломанные.
 *
 * Проверка идёт тем же путём, каким виджет считается у пользователя, —
 * выполняется его спецификация и результат сверяется с формой семейства.
 * Иначе шаг проверял бы не то, что увидит человек.
 *
 * Починка отдаёт модели её собственную спецификацию вместе с текстом ошибки
 * от базы. Это принципиально: без ошибки модель повторяет прежний ответ,
 * а с ней исправляет с первой-второй попытки — база называет причину точно
 * («Unknown column 'orderdate' in 'field list'»).
 */
class ReviewWidgetsDashboard
{
    private const MAX_FIX_ATTEMPTS = 2;

    public $dashboard_widgets = null;
    public $dashboard = null;
    public $dataSource;
    public $connectionProviderRouter;

    private ?WidgetSpecGenerator $specGenerator = null;
    private WidgetOutputValidator $outputValidator;

    public function __construct(?int $dashboardId = null, ?int $dataSourceId = null)
    {
        $this->outputValidator = new WidgetOutputValidator();

        if ($dashboardId) {
            $this->dashboard = Dashboard::find($dashboardId);

            $this->dashboard_widgets = DashboardWidget::query()
                ->where('dashboard_id', $dashboardId)
                ->with('widget.types', 'widgetType')
                ->get();
        }

        if ($dataSourceId) {
            $this->dataSource = DataSource::with('type')->find($dataSourceId);
            $this->connectionProviderRouter = new ConnectionProviderRouter($this->dataSource->id);
            $this->specGenerator = new WidgetSpecGenerator($this->dataSource);
        }
    }

    /**
     * Возвращает статус ВЫПОЛНЕНИЯ шага, а не результат проверки виджетов:
     * отдельный сломанный виджет не проваливает генерацию всего дашборда.
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

            return ['errors' => false, 'message' => null];
        } catch (Throwable $e) {
            return ['errors' => true, 'message' => $e->getMessage()];
        }
    }

    /**
     * Прогоняет все виджеты дашборда.
     *
     * @return array{result: array<int, array>, isError: bool}
     */
    public function review($dataSource, $dashboard_widgets)
    {
        $isError = false;
        $result = [];

        foreach ($dashboard_widgets ?? [] as $widget) {
            $entry = $this->runAndValidateWidget($widget);

            if (!$entry['is_valid']) {
                $isError = true;
            }

            $result[] = $entry;
        }

        return ['result' => $result, 'isError' => $isError];
    }

    /**
     * Чинит виджеты, не прошедшие проверку.
     */
    public function startReGenerate($resultsRun): void
    {
        foreach ($resultsRun as $result) {
            if (empty($result['errors'])) {
                continue;
            }

            // Содержимого нет вовсе — чинить нечего: это провал генерации,
            // а не поправимая ошибка в запросе.
            if (empty($result['has_content'])) {
                DashboardWidget::query()
                    ->where('id', $result['widget_id'])
                    ->update(['status' => 'failed']);

                continue;
            }

            $this->fixWidget($result['widget_id'], $result);
        }
    }

    /**
     * Правит спецификацию и тут же перепроверяет результат: без повторного
     * прогона статус виджета не отражал бы, помогла правка или нет.
     */
    private function fixWidget(int $widgetId, array $runResult, int $attempt = 1): void
    {
        $widget = DashboardWidget::query()->with('widget.types', 'widgetType')->find($widgetId);

        if (!$widget || !$this->specGenerator) {
            return;
        }

        $error = implode('; ', $runResult['errors'] ?? []) ?: 'Виджет не прошёл проверку.';

        $scheme = $this->schemeFor($widget);

        $repaired = $this->specGenerator->repair(
            $widget,
            $scheme,
            $widget->query_spec ?? [],
            $error
        );

        if (!$repaired['ok']) {
            Log::info('ReviewWidgetsDashboard: починить не удалось', [
                'widget_id' => $widget->id,
                'error' => $repaired['error'],
            ]);

            $widget->status = 'failed';
            $widget->last_error = $repaired['error'] ?? $error;
            $widget->save();

            return;
        }

        $widget->query_spec = $repaired['spec'];
        $widget->content_mode = $repaired['mode'];
        $widget->save();

        $recheck = $this->runAndValidateWidget($widget->fresh(['widget.types', 'widgetType']));

        if ($recheck['is_valid']) {
            $widget->status = 'active';
            $widget->last_error = null;
            $widget->save();

            return;
        }

        if ($attempt < self::MAX_FIX_ATTEMPTS) {
            $this->fixWidget($widgetId, $recheck, $attempt + 1);

            return;
        }

        $widget->status = 'failed';
        $widget->last_error = implode('; ', $recheck['errors'] ?? []);
        $widget->save();
    }

    /**
     * Выполняет виджет и сверяет результат с формой его семейства.
     *
     * @return array{widget_id: ?int, widget_type: ?string, output: mixed, is_valid: bool, errors: array, has_content: bool}
     */
    private function runAndValidateWidget(DashboardWidget $widget): array
    {
        $family = $widget->widget->name ?? null;
        $view = $widget->effectiveType()?->name;

        if (!$widget->usesQuerySpec()) {
            // Виджеты, написанные до перехода на запросы, этот шаг не трогает:
            // их содержимое — Python, а чинить его больше нечем и незачем.
            return $this->entry($widget, $family, null, [
                $widget->hasContent()
                    ? 'Виджет использует прежний способ расчёта — проверка пропущена.'
                    : 'Содержимое виджета не сгенерировано.',
            ], hasContent: $widget->hasContent(), skip: $widget->hasContent());
        }

        try {
            $run = (new WidgetQueryRunner($this->dataSource))->run(
                spec: $widget->query_spec,
                family: $family,
                type: $view,
                sample: true
            );
        } catch (Throwable $e) {
            return $this->entry($widget, $family, null, [
                WidgetSpecValidator::cleanDatabaseError($e->getMessage()),
            ], hasContent: true);
        }

        if (!($run['ok'] ?? false)) {
            return $this->entry($widget, $family, null, [
                WidgetSpecValidator::cleanDatabaseError((string) ($run['error'] ?? 'Запрос не выполнен.')),
            ], hasContent: true);
        }

        // Проверка идёт по нескольким строкам: смотрим на структуру результата,
        // а не на объём данных — для этого хватает выборки.
        $errors = $family
            ? $this->outputValidator->validate($family, $run['data'], $view)
            : ['Не удалось определить семейство виджета.'];

        return $this->entry($widget, $family, $run['data'], $errors, hasContent: true);
    }

    /**
     * Схема таблиц виджета для повторной генерации.
     */
    private function schemeFor(DashboardWidget $widget): array
    {
        $tables = $widget->tables ?? [];

        if ($tables === [] || !$this->connectionProviderRouter) {
            return [];
        }

        try {
            return $this->connectionProviderRouter->getSchema($tables, SchemaOptions::basic());
        } catch (Throwable $e) {
            Log::warning('ReviewWidgetsDashboard: не удалось прочитать схему', [
                'widget_id' => $widget->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param array<int, string> $errors
     */
    private function entry(
        DashboardWidget $widget,
        ?string $family,
        mixed $output,
        array $errors,
        bool $hasContent,
        bool $skip = false
    ): array {
        return [
            'widget_id' => $widget->id,
            'widget_type' => $family,
            'output' => $output,
            // Пропущенный виджет не считается сломанным: чинить его нечем,
            // и помечать провалом рабочий виджет было бы неверно.
            'is_valid' => $skip || empty($errors),
            'errors' => $skip ? [] : $errors,
            'has_content' => $hasContent,
        ];
    }
}
