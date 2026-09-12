<?php

namespace App\Http\Controllers\Alert;

use App\Http\Controllers\Controller;
use App\Models\AlertCheckerHistory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Скачивание CSV одной проверки алерта — по публичной ссылке, как выгрузки
 * чата (см. Chat\ExportController).
 *
 * Маршрут намеренно без авторизации: ссылка уходит в письме на любой
 * указанный адрес — не обязательно тому, у кого вообще есть учётка в
 * платформе. Защита — как у ChatExport: 48 случайных символов в токене,
 * путь на диске наружу не отдаётся, а сам файл живёт ровно до тех пор, пока
 * жива запись истории (alerts:prune удаляет обоих разом).
 */
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
