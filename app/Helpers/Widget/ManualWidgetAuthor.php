<?php

namespace App\Helpers\Widget;

use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\DataSource\ReadOnlySqlGuard;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class ManualWidgetAuthor
{

    private const PREVIEW_ROWS = 20;

    public function __construct(
        private WidgetCodeInspector $inspector = new WidgetCodeInspector(),
        private WidgetCodeRun $codeRun = new WidgetCodeRun(),
        private WidgetOutputValidator $outputValidator = new WidgetOutputValidator(),
    ) {
    }

    public function runBuilderDraft(
        DashboardWidget $widget,
        array $builder,
        array $presentation,
        DataSource $dataSource
    ): array {
        $family = $widget->widget?->name;

        if (!$family) {
            return $this->emptyQueryResult(['У виджета не задано семейство — неизвестно, как раскладывать данные.']);
        }

        $composed = (new WidgetQueryComposer($dataSource))->compose(
            $builder,
            $family,
            $widget->effectiveType()?->name
        );

        if (!$composed['ok']) {
            return $this->emptyQueryResult($composed['errors']);
        }

        $presentation = array_replace($composed['presentation'] ?? [], $presentation);

        return $this->runQueryDraft($widget, $composed['sql'], $presentation, $dataSource)
            + ['sql' => $composed['sql'], 'presentation' => $presentation];
    }

    public function saveBuilder(
        DashboardWidget $widget,
        array $builder,
        array $presentation,
        DataSource $dataSource
    ): array {
        $run = $this->runBuilderDraft($widget, $builder, $presentation, $dataSource);

        if (!$run['ok']) {
            return [
                'ok' => false,
                'saved' => false,
                'data' => null,
                'errors' => $run['errors'],
                'sql' => $run['sql'] ?? null,
            ];
        }

        $saved = $this->saveQuery($widget, $run['sql'], $run['presentation'] ?? $presentation, $dataSource);

        if ($saved['saved']) {
            $spec = $widget->query_spec;
            $spec['mode'] = DashboardWidget::MODE_BUILDER;
            $spec['builder'] = $builder;

            $widget->query_spec = $spec;

            $widget->content_mode = DashboardWidget::MODE_BUILDER;
            $widget->save();
        }

        return $saved + ['sql' => $run['sql']];
    }

    public function rebuildForType(DashboardWidget $widget, DataSource $dataSource): bool
    {
        $builder = $widget->query_spec['builder'] ?? null;
        $family = $widget->widget?->name;

        if (!$builder || !$family) {
            return false;
        }

        try {
            $composed = (new WidgetQueryComposer($dataSource))->compose(
                $builder,
                $family,
                $widget->effectiveType()?->name
            );
        } catch (Throwable $e) {

            $composed = ['ok' => false, 'errors' => [$e->getMessage()]];
        }

        if (!$composed['ok']) {

            $widget->last_error = $composed['errors'][0] ?? null;
            $widget->status = 'failed';
            $widget->save();

            return true;
        }

        $spec = $widget->query_spec;
        $spec['queries'] = ['main' => $composed['sql']];

        if (($composed['presentation'] ?? []) !== []) {
            $spec['presentation'] = array_replace($spec['presentation'] ?? [], $composed['presentation']);
        }

        $widget->query_spec = $spec;
        $widget->last_error = null;
        $widget->status = 'active';
        $widget->save();

        return true;
    }

    public function runQueryDraft(
        DashboardWidget $widget,
        string $sql,
        array $presentation,
        DataSource $dataSource
    ): array {
        $family = $widget->widget?->name;

        if (!$family) {
            return $this->emptyQueryResult(['У виджета не задано семейство — неизвестно, какие колонки нужны.']);
        }

        $type = $widget->effectiveType()?->name;

        $spec = WidgetSpecValidator::build($family, $sql, $presentation);

        $check = (new WidgetSpecValidator($dataSource))->validate($spec, $family, $type);

        if (!$check['ok']) {
            return [
                'ok' => false,
                'data' => null,
                'errors' => $check['errors'],
                'rows' => [],
                'columns' => $check['columns'],
            ];
        }

        $preview = $this->sampleRows($dataSource, $sql);

        $run = (new WidgetQueryRunner($dataSource))->run(
            spec: $spec,
            family: $family,
            type: $type
        );

        if (!($run['ok'] ?? false)) {
            return [
                'ok' => false,
                'data' => null,
                'errors' => [
                    WidgetSpecValidator::cleanDatabaseError(
                        (string) ($run['error'] ?? 'Запрос не выполнен.')
                    ),
                ],
                'rows' => $preview,
                'columns' => $check['columns'],
            ];
        }

        return [
            'ok' => true,
            'data' => $run['data'],
            'errors' => [],
            'rows' => $preview,
            'columns' => $check['columns'],
        ];
    }

    public function saveQuery(
        DashboardWidget $widget,
        string $sql,
        array $presentation,
        DataSource $dataSource
    ): array {
        $run = $this->runQueryDraft($widget, $sql, $presentation, $dataSource);

        if (!$run['ok']) {
            return [
                'ok' => false,
                'saved' => false,
                'data' => null,
                'errors' => $run['errors'],
            ];
        }

        $family = $widget->widget->name;

        $widget->query_spec = $this->nextQuerySpec($widget, $family, $sql, $presentation);
        $widget->content_mode = DashboardWidget::MODE_SQL;
        $widget->origin = DashboardWidget::ORIGIN_MANUAL;
        $widget->status = 'active';
        $widget->last_error = null;
        $widget->last_run_at = now();
        $widget->save();

        Log::info('ManualWidgetAuthor: запрос виджета сохранён', [
            'widget_id' => $widget->id,
            'dashboard_id' => $widget->dashboard_id,
            'user_id' => auth()->id(),
            'length' => mb_strlen($sql),
        ]);

        return [
            'ok' => true,
            'saved' => true,
            'data' => $run['data'],
            'errors' => [],
        ];
    }

    private function nextQuerySpec(DashboardWidget $widget, string $family, string $sql, array $presentation): array
    {
        $existingQueries = $widget->query_spec['queries'] ?? null;

        $untouched = is_array($existingQueries)
            && count($existingQueries) > 1
            && trim($sql) === trim((string) WidgetSpecValidator::primaryQueryOf($widget->query_spec ?? []));

        if (!$untouched) {
            return WidgetSpecValidator::build($family, $sql, $presentation);
        }

        $spec = $widget->query_spec;
        $spec['shape'] = WidgetShapeMapper::shapeFor($family);

        if ($presentation !== []) {
            $spec['presentation'] = $presentation;
        } else {
            unset($spec['presentation']);
        }

        return $spec;
    }

    private function emptyQueryResult(array $errors): array
    {
        return [
            'ok' => false,
            'data' => null,
            'errors' => array_values($errors),
            'rows' => [],
            'columns' => [],
            'sql' => null,
        ];
    }

    private function sampleRows(DataSource $dataSource, string $sql): array
    {
        try {
            $safe = ReadOnlySqlGuard::sanitize($sql, null);

            $rows = ReadOnlySqlGuard::normalizeRows(
                (new ConnectionProviderRouter($dataSource->id))->query(
                    'SELECT * FROM ('.$safe.') AS widget_preview LIMIT '.self::PREVIEW_ROWS
                )
            );

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    public function runDraft(DashboardWidget $widget, string $code, DataSource $dataSource): array
    {
        $inspection = $this->inspector->inspect($code);

        if (!$inspection['ok']) {
            return $this->fail($inspection['errors']);
        }

        $result = $this->codeRun->runSource(
            codeMain: $code,
            dataSource: $dataSource,
            timeoutSeconds: WidgetCodeRun::MANUAL_TIMEOUT,
            restricted: true
        );

        if (isset($result['error'])) {
            return $this->fail([
                $result['error'],
                (string) ($result['details'] ?? ''),
            ]);
        }

        $output = $result['output'] ?? [];

        if (($result['exit_code'] ?? 0) !== 0) {

            return $this->fail([
                'Код завершился с ошибкой.',
                trim(implode("\n", $output)),
            ]);
        }

        $first = $output[0] ?? null;
        $data = is_string($first) ? json_decode($first, true) : null;

        if (!is_array($data)) {
            return $this->fail([
                'В первой строке вывода нет JSON. Уберите лишние print — '
                . 'stdout должен содержать только один json.dumps(result).',
                trim(implode("\n", array_slice($output, 0, 5))),
            ], $first);
        }

        $family = $widget->widget?->name;

        if (!$family) {
            return $this->fail(['У виджета не задано семейство — нечем проверить форму данных.'], $first);
        }

        $shapeErrors = $this->outputValidator->validate(
            $family,
            $data,
            $widget->effectiveType()?->name
        );

        if ($shapeErrors !== []) {
            return [
                'ok' => false,
                'data' => $data,
                'errors' => $shapeErrors,
                'output' => $first,
            ];
        }

        return [
            'ok' => true,
            'data' => $data,
            'errors' => [],
            'output' => $first,
        ];
    }

    public function save(DashboardWidget $widget, string $code, DataSource $dataSource): array
    {
        $inspection = $this->inspector->inspect($code);

        if (!$inspection['ok']) {
            return [
                'ok' => false,
                'saved' => false,
                'data' => null,
                'errors' => $inspection['errors'],
            ];
        }

        $run = $this->runDraft($widget, $code, $dataSource);

        if (is_string($widget->code) && trim($widget->code) !== '' && $widget->code !== $code) {
            $widget->code_previous = $widget->code;
        }

        $widget->code = $code;
        $widget->content_mode = 'python';
        $widget->origin = DashboardWidget::ORIGIN_MANUAL;
        $widget->code_path = $this->writeFile($widget, $code);
        $widget->status = $run['ok'] ? 'active' : 'failed';
        $widget->last_error = $run['ok'] ? null : trim(implode("\n", $run['errors']));
        $widget->last_run_at = now();
        $widget->save();

        Log::info('ManualWidgetAuthor: код виджета сохранён', [
            'widget_id' => $widget->id,
            'dashboard_id' => $widget->dashboard_id,
            'user_id' => auth()->id(),
            'length' => mb_strlen($code),
            'ok' => $run['ok'],
        ]);

        return [
            'ok' => $run['ok'],
            'saved' => true,
            'data' => $run['data'],
            'errors' => $run['errors'],
        ];
    }

    public function restorePrevious(DashboardWidget $widget, DataSource $dataSource): array
    {
        $previous = $widget->code_previous;

        if (!is_string($previous) || trim($previous) === '') {
            return [
                'ok' => false,
                'saved' => false,
                'data' => null,
                'errors' => ['Предыдущей версии кода нет.'],
            ];
        }

        $current = $widget->code;
        $widget->code = $previous;
        $widget->code_previous = $current;
        $widget->save();

        return $this->save($widget, $previous, $dataSource);
    }

    private function writeFile(DashboardWidget $widget, string $code): string
    {
        $path = $this->pathFor($widget);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $code);

        return $path;
    }

    public function pathFor(DashboardWidget $widget): string
    {
        $companyId = $widget->dashboard?->company_id ?? 0;

        return storage_path(
            'app/company/' . $companyId
            . '/dashboards/' . $widget->dashboard_id
            . '/widgets/' . $widget->id
            . '/manual_script.py'
        );
    }

    public function deleteFiles(DashboardWidget $widget): void
    {
        $directory = dirname($this->pathFor($widget));

        if (is_dir($directory)) {
            File::deleteDirectory($directory);
        }

        if ($widget->code_path && is_file($widget->code_path)) {
            File::delete($widget->code_path);
        }
    }

    private function fail(array $errors, ?string $output = null): array
    {
        return [
            'ok' => false,
            'data' => null,
            'errors' => array_values(array_filter($errors, fn ($error) => trim((string) $error) !== '')),
            'output' => $output,
        ];
    }
}
