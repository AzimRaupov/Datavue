<?php

namespace App\Http\Controllers\Widget;

use App\Helpers\Widget\WidgetCodeRun;
use App\Http\Controllers\Controller;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use Illuminate\Http\Request;

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
            ->whereHas('dashboard', fn ($query) => $query->where('company_id', $companyId))
            ->findOrFail($id);

        $dataSource = DataSource::query()
            ->where('company_id', $companyId)
            ->where('chat_id', $request->input('chat_id'))
            ->firstOrFail();

        // Фронт запрашивает содержимое сразу, как только появился плейсхолдер, —
        // то есть до того, как для виджета сгенерирован код. Это не ошибка:
        // отвечаем пустым содержимым, иначе каждый плейсхолдер даёт исключение
        // и строку в логе на каждой перерисовке дашборда.
        if (!$widget->code_path || !is_file($widget->code_path)) {
            return response()->json([
                'output' => null,
                'pending' => true,
            ]);
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
}
