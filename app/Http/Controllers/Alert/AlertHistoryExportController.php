<?php

namespace App\Http\Controllers\Alert;

use App\Http\Controllers\Controller;
use App\Models\AlertCheckerHistory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AlertHistoryExportController extends Controller
{
    public function download(string $token): BinaryFileResponse
    {
        $check = AlertCheckerHistory::query()->where('csv_token', $token)->first();

        abort_if(!$check, 404, 'Файл не найден.');
        abort_if(!$check->csv_path || !is_file($check->csv_path), 404, 'Файл больше не доступен.');

        $fileName = 'alert-'.$check->alert_id.'-'.$check->checking_at->format('Y-m-d-His').'.csv';

        return response()->download($check->csv_path, $fileName, [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }
}
