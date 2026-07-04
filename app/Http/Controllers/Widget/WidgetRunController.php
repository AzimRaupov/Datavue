<?php

namespace App\Http\Controllers\Widget;

use App\Helpers\PythonRunner;
use App\Http\Controllers\Controller;
use App\Models\DashboardWidget;
use App\Models\ExtractedData;
use Illuminate\Http\Request;

class WidgetRunController extends Controller
{
    public function run($id,Request $request){

        $extracted = ExtractedData::query()->where('chat_id', $request->chat_id)->first();

        $widget = DashboardWidget::query()->find($id);

        $runner = new PythonRunner(
            $widget->code_path,
            [
                "--path={$extracted->data_path}"
            ]
        );

        $result = $runner->run();


        return response()->json($result);
    }
}
