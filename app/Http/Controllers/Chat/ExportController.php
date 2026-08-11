<?php

namespace App\Http\Controllers\Chat;

use App\Helpers\Export\ExportFormat;
use App\Http\Controllers\Controller;
use App\Models\ChatExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    /**
     * Скачивание файла по публичной ссылке из чата.
     *
     * Маршрут намеренно без авторизации: ссылку отправляют коллеге, открывают
     * с телефона, кладут в задачу — требовать сессию значило бы, что ни один
     * из этих сценариев не работает. Защита здесь другая:
     *
     *   - адрес содержит 48 случайных символов и не выводится нигде, кроме
     *     самого чата, откуда его и запросили;
     *   - у ссылки есть срок жизни (exports.ttl_days);
     *   - путь к файлу наружу не отдаётся и в адресе не участвует.
     */
    public function download(string $token): BinaryFileResponse
    {
        $export = ChatExport::query()->where('token', $token)->first();

        abort_if(!$export, 404, 'Файл не найден.');
        abort_if($export->isExpired(), 410, 'Срок действия ссылки истёк — попросите выгрузку заново.');
        abort_if(!is_file($export->path), 404, 'Файл больше не доступен.');

        return response()->download($export->path, $export->file_name, [
            'Content-Type' => ExportFormat::mime($export->format),
        ]);
    }

    /**
     * Выгрузки чата — для истории и повторного скачивания из интерфейса.
     */
    public function index(Request $request)
    {
        $request->validate([
            'chat_id' => 'required|integer',
        ]);

        $exports = ChatExport::query()
            ->where('company_id', auth()->user()->company->id)
            ->where('chat_id', $request->chat_id)
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json($exports);
    }
}
