<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\DashboardExportJob;
use App\Models\Dashboard;
use App\Models\DashboardExport;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DashboardExportController extends Controller
{
    /**
     * Ставит выгрузку дашборда в очередь. Файл делает задача: открывает страницу
     * дашборда в headless Chrome и снимает её (см. DashboardExportJob).
     */
    public function store(Request $request, int $dashboard): JsonResponse
    {
        $data = $request->validate([
            'format' => 'required|in:'.implode(',', DashboardExport::FORMATS),
        ]);

        $user = $request->user();

        $model = Dashboard::query()
            ->where('company_id', $user->company_id)
            ->findOrFail($dashboard);

        // Повторное нажатие, пока прошлая выгрузка не закончилась, не плодит задачи.
        $active = DashboardExport::query()
            ->where('dashboard_id', $model->id)
            ->where('user_id', $user->id)
            ->where('format', $data['format'])
            ->whereIn('status', [DashboardExport::PENDING, DashboardExport::PROCESSING])
            ->where('created_at', '>', now()->subMinutes(15))
            ->latest('id')
            ->first();

        if ($active) {
            return response()->json($active, 202);
        }

        $export = DashboardExport::query()->create([
            'company_id' => $user->company_id,
            'dashboard_id' => $model->id,
            'user_id' => $user->id,
            'token' => DashboardExport::newToken(),
            'format' => $data['format'],
            'status' => DashboardExport::PENDING,
        ]);

        DashboardExportJob::dispatch($export->id);

        return response()->json($export, 202);
    }

    /** Состояние выгрузки — фронтенд опрашивает его, пока файл не будет готов. */
    public function show(Request $request, int $export): JsonResponse
    {
        $model = DashboardExport::query()
            ->where('company_id', $request->user()->company_id)
            ->where('user_id', $request->user()->id)
            ->findOrFail($export);

        return response()->json(array_merge($model->toArray(), [
            'error' => $model->status === DashboardExport::FAILED
                ? ($model->error ?: 'Не удалось сформировать файл.')
                : null,
        ]));
    }

    /** Скачивание по токену из ссылки — без входа, как у выгрузок чата. */
    public function download(string $token): BinaryFileResponse
    {
        $export = DashboardExport::query()->where('token', $token)->first();

        abort_if(!$export, 404, 'Файл не найден.');
        abort_if($export->isExpired(), 410, 'Срок действия ссылки истёк — выгрузите дашборд заново.');
        abort_if(!$export->isDownloadable(), 404, 'Файл больше не доступен.');

        $mime = $export->format === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        return response()->download($export->path, $export->file_name, ['Content-Type' => $mime]);
    }
}
