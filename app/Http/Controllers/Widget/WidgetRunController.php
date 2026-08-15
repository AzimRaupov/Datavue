<?php

namespace App\Http\Controllers\Widget;

use App\Helpers\Widget\WidgetCodeRun;
use App\Helpers\Widget\WidgetQueryRunner;
use App\Helpers\Widget\WidgetSpecValidator;
use App\Http\Controllers\Controller;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use Illuminate\Http\Request;
use Throwable;

class WidgetRunController extends Controller
{
    public function run(
        int $id,
        Request $request,
        WidgetCodeRun $widgetCodeRun
    ) {
        $companyId = $request->user()->company_id;

        // ВАЖНО: и виджет, и источник данных обязаны принадлежать компании
        // текущего пользователя. Раньше проверки не было вовсе — по чужому id
        // можно было выполнить чужой Python-скрипт и получить данные другой компании.
        $widget = DashboardWidget::query()
            ->with(['dashboard', 'widget.types', 'widgetType'])
            ->whereHas('dashboard', fn ($query) => $query->where('company_id', $companyId))
            ->findOrFail($id);

        // Источник берём от самого дашборда.
        //
        // Dashboard::resolveDataSource() сначала смотрит на dashboards.data_source_id
        // (так устроен дашборд, собранный руками — чата у него нет), и только
        // потом идёт прежним путём через чат. Дашборды, сгенерированные до
        // появления конструктора, продолжают резолвиться как раньше.
        //
        // chat_id из тела запроса в выборе источника не участвует.
        $dataSource = $widget->dashboard?->resolveDataSource();

        if (!$dataSource || $dataSource->company_id !== $companyId) {
            return response()->json([
                'error' => 'Источник данных для этого виджета не найден.',
            ], 422);
        }

        // Фронт запрашивает содержимое сразу, как только появился плейсхолдер, —
        // то есть до того, как для виджета сгенерирован код. Это не ошибка:
        // отвечаем пустым содержимым, иначе каждый плейсхолдер даёт исключение
        // и строку в логе на каждой перерисовке дашборда. Ручной виджет живёт
        // в этом состоянии дольше всех: он создаётся пустым и ждёт, пока автор
        // напишет ему код.
        if (!$widget->hasContent()) {
            return response()->json([
                'output' => null,
                'pending' => true,
            ]);
        }

        // Развилка между двумя способами получить содержимое виджета.
        //
        // Спецификация — основной путь: запрос выполняет база, а раскладку по
        // форме виджета делает платформа. Python остался у виджетов, созданных
        // до перехода: их никто не переписывал, и ломаться они не должны.
        if ($widget->usesQuerySpec()) {
            return $this->runQuerySpec($widget, $dataSource);
        }

        $result = $widgetCodeRun->run(
            widget: $widget,
            dataSource: $dataSource
        );

        if (isset($result['error'])) {
            return response()->json(
                $result,
                422
            );
        }

        return response()->json(
            $result
        );
    }

    /**
     * Содержимое виджета из SQL-спецификации.
     *
     * Ответ отдаётся полем data — готовой структурой, а не строкой, которую
     * фронту пришлось бы разбирать. Поле output сохранено ради совместимости:
     * на Python-пути содержимое приходит именно так, и фронт умеет оба формата.
     */
    private function runQuerySpec(DashboardWidget $widget, DataSource $dataSource)
    {
        $family = $widget->widget?->name;

        if (!$family) {
            return response()->json([
                'error' => 'У виджета не задано семейство — нечем определить форму данных.',
            ], 422);
        }

        try {
            $result = (new WidgetQueryRunner($dataSource))->run(
                spec: $widget->query_spec,
                family: $family,
                type: $widget->effectiveType()?->name
            );
        } catch (Throwable $e) {
            return response()->json([
                'error' => WidgetSpecValidator::cleanDatabaseError($e->getMessage()),
            ], 422);
        }

        if (!($result['ok'] ?? false)) {
            // Реквизиты подключения к базе клиента не должны уезжать
            // на страницу вместе с текстом ошибки.
            return response()->json([
                'error' => WidgetSpecValidator::cleanDatabaseError(
                    (string) ($result['error'] ?? 'Запрос виджета не выполнен.')
                ),
            ], 422);
        }

        return response()->json([
            'data' => $result['data'],
            'meta' => $result['meta'] ?? null,
        ]);
    }
}
