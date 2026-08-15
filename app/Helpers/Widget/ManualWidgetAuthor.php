<?php

namespace App\Helpers\Widget;

use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\DataSource\ReadOnlySqlGuard;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Всё, что происходит с кодом виджета, который пишет человек.
 *
 * Собрано в одном месте, потому что путей два — «выполнить черновик» и
 * «сохранить», — а правила у них общие: сначала проверка, потом запуск, и
 * только потом запись. Разъехавшись по контроллеру, эти правила разошлись бы
 * и на одном из путей код попадал бы на сервер непроверенным.
 */
class ManualWidgetAuthor
{
    /** Сколько строк результата показываем автору под редактором. */
    private const PREVIEW_ROWS = 20;

    public function __construct(
        private WidgetCodeInspector $inspector = new WidgetCodeInspector(),
        private WidgetCodeRun $codeRun = new WidgetCodeRun(),
        private WidgetOutputValidator $outputValidator = new WidgetOutputValidator(),
    ) {
    }

    /**
     * Прогон запроса без сохранения — кнопка «Выполнить» в редакторе SQL.
     *
     * Порядок тот же, что у кода: сначала проверка, потом выполнение. Разница
     * в том, что здесь проверить может сама база — пробным запросом с LIMIT 1,
     * и ошибка приходит с точным именем несуществующей колонки.
     *
     * @return array{ok: bool, data: mixed, errors: array<int, string>, rows: array, columns: array}
     */
    /**
     * Прогон настроек конструктора: сначала собираем запрос, дальше — общий
     * путь с режимом «сырой SQL».
     *
     * Собранный запрос возвращается автору всегда, даже при ошибке: увидеть,
     * ЧТО ушло в базу, — половина починки. Это же делает Superset кнопкой
     * «View query».
     *
     * @return array{ok: bool, data: mixed, errors: array<int, string>, rows: array, columns: array, sql: ?string}
     */
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

        // Оформление от настроек и оформление от автора складываются, причём
        // автор главнее: он мог сознательно переназначить то, что конструктор
        // выставил сам.
        $presentation = array_replace($composed['presentation'] ?? [], $presentation);

        return $this->runQueryDraft($widget, $composed['sql'], $presentation, $dataSource)
            + ['sql' => $composed['sql'], 'presentation' => $presentation];
    }

    /**
     * Сохранение настроек конструктора.
     *
     * В спецификацию попадает и декларация, и собранный из неё запрос:
     * декларация нужна, чтобы открыть виджет в конструкторе и продолжить
     * править слотами, а запрос — чтобы выполнение шло тем же путём, что
     * и у виджетов, написанных запросом вручную.
     *
     * @return array{ok: bool, saved: bool, data: mixed, errors: array<int, string>, sql: ?string}
     */
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

        // Оформление берём то, что вернул прогон: в нём уже учтено всё,
        // что конструктор выставил сам.
        $saved = $this->saveQuery($widget, $run['sql'], $run['presentation'] ?? $presentation, $dataSource);

        if ($saved['saved']) {
            $spec = $widget->query_spec;
            $spec['mode'] = DashboardWidget::MODE_BUILDER;
            $spec['builder'] = $builder;

            $widget->query_spec = $spec;
            // Режим правим здесь же: saveQuery() пометил виджет как написанный
            // запросом, а он собран конструктором. Разошедшись, эти два поля
            // говорили бы о виджете разное.
            $widget->content_mode = DashboardWidget::MODE_BUILDER;
            $widget->save();
        }

        return $saved + ['sql' => $run['sql']];
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

        // Строки показываем как есть, рядом с готовым виджетом: автору важно
        // видеть и то, что вернула база, и то, во что это разложилось.
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

    /**
     * Сохраняет спецификацию виджета.
     *
     * В отличие от кода на Python, спецификация с ошибкой не сохраняется
     * вовсе: запрос проверяется базой заранее, и «сохранил, а оно не работает»
     * здесь просто не может случиться по вине синтаксиса или опечатки
     * в названии колонки.
     *
     * @return array{ok: bool, saved: bool, data: mixed, errors: array<int, string>}
     */
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

        $widget->query_spec = WidgetSpecValidator::build($family, $sql, $presentation);
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

    /**
     * @param array<int, string> $errors
     *
     * @return array{ok: bool, data: mixed, errors: array<int, string>, rows: array, columns: array, sql: ?string}
     */
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

    /**
     * Несколько строк результата для предпросмотра «как вернула база».
     *
     * Ошибки здесь не важны: запрос к этому моменту уже проверен, а если
     * выборка почему-то не удалась — показываем виджет без таблицы, но
     * не роняем весь предпросмотр.
     *
     * @return array<int, array<string, mixed>>
     */
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

    /**
     * Прогон кода без сохранения — предпросмотр в конструкторе.
     *
     * @return array{ok: bool, data: mixed, errors: array<int, string>, output: ?string}
     */
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
            // Трейсбек питона — самое полезное, что можно показать автору,
            // поэтому отдаём его как есть, а не прячем за общей фразой.
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

        // Форму проверяет тот же валидатор, что и сгенерированные виджеты:
        // иначе ручной виджет сохранился бы «успешно» и остался заглушкой
        // на дашборде — данные есть, а нарисовать их нечем.
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

    /**
     * Сохраняет код виджета.
     *
     * Код не проходит проверку — не сохраняем вовсе. Код проверку прошёл, но
     * упал на данных — сохраняем и помечаем виджет сломанным: автору нужно
     * куда-то вернуться, чтобы починить, а причина лежит в last_error.
     *
     * @return array{ok: bool, saved: bool, data: mixed, errors: array<int, string>}
     */
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

        // Предыдущая версия остаётся ровно одна — этого хватает, чтобы
        // откатить неудачную правку, и не превращает таблицу в архив.
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

    /**
     * Возвращает виджет к предыдущей версии кода.
     *
     * @return array{ok: bool, saved: bool, data: mixed, errors: array<int, string>}
     */
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

        // Меняем версии местами: откат тоже можно откатить.
        $current = $widget->code;
        $widget->code = $previous;
        $widget->code_previous = $current;
        $widget->save();

        return $this->save($widget, $previous, $dataSource);
    }

    /**
     * Материализует код в файл — путь запуска у ручных и сгенерированных
     * виджетов остаётся общим (WidgetCodeRun читает code_path, если в базе
     * кода нет).
     */
    private function writeFile(DashboardWidget $widget, string $code): string
    {
        $path = $this->pathFor($widget);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $code);

        return $path;
    }

    /**
     * Ручной дашборд не привязан к чату, поэтому и файлы его виджетов лежат
     * не в chats/, а в dashboards/.
     */
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

    /**
     * Удаляет файлы кода виджета. Вызывается при удалении виджета и дашборда,
     * иначе storage копит скрипты, на которые уже никто не сошлётся.
     */
    public function deleteFiles(DashboardWidget $widget): void
    {
        $directory = dirname($this->pathFor($widget));

        if (is_dir($directory)) {
            File::deleteDirectory($directory);
        }

        // У сгенерированного виджета файл лежит в каталоге чата.
        if ($widget->code_path && is_file($widget->code_path)) {
            File::delete($widget->code_path);
        }
    }

    /**
     * @param array<int, string> $errors
     *
     * @return array{ok: bool, data: mixed, errors: array<int, string>, output: ?string}
     */
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
