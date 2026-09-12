<?php

namespace App\Helpers\Alert;

use App\Helpers\Widget\WidgetSpecValidator;
use App\Models\Alert;
use App\Models\AlertCheckerHistory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Единая точка входа для проверки алерта — и по расписанию, и по кнопке
 * «Проверить сейчас».
 *
 * Порядок неизменен для обоих путей:
 *   1. выполнить условие (SQL или Python);
 *   2. записать результат в историю — ВСЕГДА, включая ошибку;
 *   3. сохранить результат в CSV — КАЖДАЯ проверка, а не только сработавшая;
 *   4. решить, нужно ли письмо (машина состояний AlertNotifier) и, если
 *      письмо уходит, приложить к нему тот же CSV.
 *
 * Ручная проверка ($notify=false) отличается только последним шагом: она
 * пишется в историю как manual и никогда не рассылает писем — иначе
 * «посмотреть, что вернёт запрос» стало бы поводом разбудить почту компании.
 * CSV при этом всё равно сохраняется — скачать его можно из истории.
 */
class AlertChecker
{
    public function __construct(
        private AlertRunner $runner = new AlertRunner(),
        private AlertCodeRun $codeRun = new AlertCodeRun(),
        private AlertNotifier $notifier = new AlertNotifier(),
    ) {
    }

    public function check(
        Alert $alert,
        string $source = AlertCheckerHistory::SOURCE_SCHEDULE,
        bool $notify = true
    ): AlertCheckerHistory {
        $checkingAt = now();
        $startedAt = microtime(true);

        $outcome = $this->evaluate($alert);

        $check = AlertCheckerHistory::create([
            'alert_id' => $alert->id,
            'checking_at' => $checkingAt,
            'finished_at' => now(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'status' => $outcome['status'],
            'value' => $outcome['value'] === null ? null : (is_scalar($outcome['value']) ? (string) $outcome['value'] : json_encode($outcome['value'])),
            'matched_rows' => $outcome['matched_rows'] ?? null,
            'payload' => $outcome['payload'] ?? null,
            'message' => $outcome['message'] ?? null,
            'error' => $outcome['error'] ?? null,
            'trigger_source' => $source,
        ]);

        $alert->last_checked_at = $checkingAt;
        $alert->save();

        // CSV — для КАЖДОЙ проверки, у которой вообще есть что экспортировать
        // (ошибка условия рядов не даёт: запрос до данных не добрался).
        // Отдельно от $notify: ручная «Проверить сейчас» писем не шлёт, но
        // файл в истории должен появиться так же, как и у плановой проверки.
        if ($outcome['status'] !== AlertCheckerHistory::STATUS_ERROR) {
            $this->attachCsv($check, $alert, $outcome['export_rows'] ?? []);
        }

        if (!$notify) {
            return $check;
        }

        $notifyResult = match ($outcome['status']) {
            AlertCheckerHistory::STATUS_OK => $this->notifier->handleOk($alert, $check),
            AlertCheckerHistory::STATUS_TRIGGERED => $this->notifier->handleTriggered($alert, $check),
            AlertCheckerHistory::STATUS_ERROR => $this->notifier->handleError($alert, $check),
        };

        $check->fill([
            'notified' => $notifyResult['notified'],
            'notified_at' => $notifyResult['notified_at'],
            'recipients' => $notifyResult['recipients'],
            'notify_error' => $notifyResult['notify_error'],
        ])->save();

        return $check;
    }

    /**
     * @return array{status: string, value: mixed, matched_rows: ?int, payload: ?array, message: ?string, error: ?string, export_rows: array}
     */
    private function evaluate(Alert $alert): array
    {
        $dataSource = $alert->dataSource;

        if (!$dataSource) {
            return $this->error('У алерта не задан источник данных.');
        }

        try {
            if ($alert->mode === Alert::MODE_PYTHON) {
                return $this->evaluatePython($alert, $dataSource);
            }

            return $this->evaluateSql($alert, $dataSource);
        } catch (Throwable $e) {
            return $this->error(WidgetSpecValidator::cleanDatabaseError($e->getMessage()));
        }
    }

    private function evaluateSql(Alert $alert, $dataSource): array
    {
        $sampleRows = (int) config('alerts.sample_rows');
        $csvMaxRows = (int) config('alerts.csv_max_rows');

        $run = $this->runner->run($alert, $dataSource, $sampleRows, $csvMaxRows);
        $condition = AlertCondition::evaluate($alert->condition ?? [], $run['row_count'], $run['first_row']);

        return [
            'status' => $condition['triggered'] ? AlertCheckerHistory::STATUS_TRIGGERED : AlertCheckerHistory::STATUS_OK,
            'value' => $condition['value'],
            'matched_rows' => $run['row_count'],
            'payload' => $run['sample'],
            'export_rows' => $run['export_rows'],
            'message' => $condition['message'],
            'error' => null,
        ];
    }

    private function evaluatePython(Alert $alert, $dataSource): array
    {
        $result = $this->codeRun->run($alert, $dataSource, (int) config('alerts.python_timeout'));

        if (!$result['ok']) {
            return $this->error($result['error']);
        }

        $rows = $result['rows'] ?? [];
        $sampleRows = (int) config('alerts.sample_rows');
        $csvMaxRows = (int) config('alerts.csv_max_rows');

        return [
            'status' => $result['triggered'] ? AlertCheckerHistory::STATUS_TRIGGERED : AlertCheckerHistory::STATUS_OK,
            'value' => $result['value'],
            'matched_rows' => count($rows),
            'payload' => array_slice($rows, 0, $sampleRows),
            'export_rows' => array_slice($rows, 0, $csvMaxRows),
            'message' => $result['message'],
            'error' => null,
        ];
    }

    private function error(string $message): array
    {
        return [
            'status' => AlertCheckerHistory::STATUS_ERROR,
            'value' => null,
            'matched_rows' => null,
            'payload' => null,
            'export_rows' => [],
            'message' => null,
            'error' => $message,
        ];
    }

    /**
     * Пишет CSV на диск и дописывает путь/токен/число строк в историю.
     *
     * Ошибка записи файла не должна ронять саму проверку: результат уже
     * посчитан и сохранён в payload, файл — это удобство поверх него,
     * а не то, от чего зависит состояние алерта.
     */
    private function attachCsv(AlertCheckerHistory $check, Alert $alert, array $rows): void
    {
        try {
            $directory = storage_path(
                'app/company/'.$alert->company_id.
                '/alerts/'.$alert->id.
                '/history/'.$check->id.'-'.Str::random(8)
            );

            $path = $directory.'/result.csv';
            $rowCount = AlertCsvWriter::write($rows, $path);

            $check->fill([
                'csv_path' => $path,
                'csv_token' => AlertCheckerHistory::newCsvToken(),
                'csv_row_count' => $rowCount,
            ])->save();
        } catch (Throwable $e) {
            Log::warning('Alert: не удалось сохранить CSV проверки', [
                'alert_id' => $alert->id,
                'check_id' => $check->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
