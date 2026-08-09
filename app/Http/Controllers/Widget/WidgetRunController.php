<?php

namespace App\Http\Controllers\Widget;

use App\Helpers\Widget\WidgetCodeRun;
use App\Http\Controllers\Controller;
use App\Models\AiChat;
use App\Models\DashboardWidget;
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
            ->with('dashboard')
            ->whereHas('dashboard', fn ($query) => $query->where('company_id', $companyId))
            ->findOrFail($id);

        // Источник берём от самого виджета — через его дашборд и чат.
        //
        // Раньше он искался как DataSource::where('chat_id', $request->chat_id):
        // во-первых, эта связь больше не заполняется (источник принадлежит
        // компании, а к чату привязан полем ai_chats.data_source_id), поэтому
        // firstOrFail() валился на каждом виджете, и дашборд оставался пустым
        // при полностью успешной генерации. Во-вторых, chat_id приходил от
        // клиента — теперь он не участвует в выборе источника вообще.
        $chat = AiChat::query()->find($widget->dashboard?->chat_id);

        $dataSource = $chat?->resolveDataSource();

        if (!$dataSource || $dataSource->company_id !== $companyId) {
            return response()->json([
                'error' => 'Источник данных для этого виджета не найден.',
            ], 422);
        }

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
