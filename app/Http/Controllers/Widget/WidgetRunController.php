<?php

namespace App\Http\Controllers\Widget;

use App\Helpers\DataSource\CodeTemplater;
use App\Helpers\PythonRunner;
use App\Http\Controllers\Controller;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use Illuminate\Http\Request;

class WidgetRunController extends Controller
{
    public function run($id, Request $request)
    {

        $dataSource = DataSource::query()
            ->where('chat_id', $request->chat_id)
            ->firstOrFail();

        $widget = DashboardWidget::query()->findOrFail($id);

        $codeMain = $this->normalizeCode(
            file_get_contents($widget->code_path)
        );

        $codeTemplater = new CodeTemplater($dataSource->id);

        $parts = [
            $codeTemplater->getLibraries(),
            $codeTemplater->getQueryTemplate(false),
            $codeMain,
            "if __name__ == \"__main__\":\n    main()\n",
        ];

        $fullCode = implode("\n", array_map('rtrim', $parts)) . "\n";

        $syntaxError = $this->checkSyntax($fullCode);
        if ($syntaxError) {
            return response()->json([
                'error' => 'Ошибка синтаксиса в сгенерированном коде',
                'details' => $syntaxError,
                'code' => $fullCode,
            ], 422);
        }
        $runner = new PythonRunner();
        $result = $runner->runCode($fullCode);

        return response()->json($result);
    }

    private function normalizeCode(string $code): string
    {
        $code = preg_replace('/^\x{FEFF}/u', '', $code);
        $code = str_replace(["\r\n", "\r"], "\n", $code);
        $code = str_replace("\t", '    ', $code);

        return trim($code) . "\n";
    }

    private function checkSyntax(string $code): ?string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'widget_') . '.py';
        file_put_contents($tmpFile, $code);

        $output = [];
        $exitCode = 0;
        exec("python3 -m py_compile " . escapeshellarg($tmpFile) . " 2>&1", $output, $exitCode);

        unlink($tmpFile);
        @unlink($tmpFile . 'c');

        if ($exitCode !== 0) {
            return implode("\n", $output);
        }

        return null;
    }
}
