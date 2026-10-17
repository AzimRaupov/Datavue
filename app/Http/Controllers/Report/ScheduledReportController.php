<?php

namespace App\Http\Controllers\Report;

use App\Helpers\Report\ReportSchedule;
use App\Http\Controllers\Controller;
use App\Jobs\ScheduledReportJob;
use App\Models\Dashboard;
use App\Models\DashboardExport;
use App\Models\ScheduledReport;
use App\Models\ScheduledReportRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScheduledReportController extends Controller
{
    public function index(Request $request, int $workspaceId): JsonResponse
    {
        $workspace = $this->findWorkspace($request, $workspaceId);

        $reports = ScheduledReport::query()
            ->where('workspace_id', $workspace->id)
            ->with('dashboard:id,name')
            ->orderByDesc('id')
            ->get();

        return response()->json($reports->map(fn (ScheduledReport $report) => $this->card($report))->values());
    }

    public function store(Request $request, int $workspaceId): JsonResponse
    {
        $workspace = $this->findWorkspace($request, $workspaceId);

        $limit = (int) config('reports.max_per_company');

        if ($limit > 0 && ScheduledReport::query()->where('company_id', $workspace->company_id)->count() >= $limit) {
            return response()->json(['message' => "Достигнут предел отчётов компании ({$limit})."], 422);
        }

        $data = $this->validated($request, $workspace);

        $report = new ScheduledReport($data + [
            'company_id' => $workspace->company_id,
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
        ]);

        $report->next_run_at = ReportSchedule::next($report);
        $report->save();

        return response()->json($this->card($report->fresh('dashboard:id,name')), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->card($this->find($request, $id)));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $report = $this->find($request, $id);

        $data = $this->validated($request, $report->workspace);

        $report->fill($data);

        // Расписание могло измениться — следующий запуск считаем заново.
        $report->next_run_at = ReportSchedule::next($report);
        $report->consecutive_failures = 0;

        if ($report->is_active) {
            $report->disabled_reason = null;
        }

        $report->save();

        return response()->json($this->card($report->fresh('dashboard:id,name')));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->find($request, $id)->delete();

        return response()->json(['message' => 'Отчёт удалён.']);
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $report = $this->find($request, $id);

        $report->is_active = !$report->is_active;

        if ($report->is_active) {
            $report->disabled_reason = null;
            $report->consecutive_failures = 0;
            // Время могло остаться в прошлом, пока отчёт был выключен: иначе он ушёл бы сразу же.
            $report->next_run_at = ReportSchedule::next($report);
        }

        $report->save();

        return response()->json($this->card($report->fresh('dashboard:id,name')));
    }

    /** «Отправить сейчас»: тот же отчёт, вне расписания (расписание при этом не сдвигается). */
    public function run(Request $request, int $id): JsonResponse
    {
        $report = $this->find($request, $id);

        $busy = ScheduledReportRun::query()
            ->where('scheduled_report_id', $report->id)
            ->where('status', ScheduledReportRun::RUNNING)
            ->where('started_at', '>', now()->subMinutes(20))
            ->exists();

        if ($busy) {
            return response()->json(['message' => 'Этот отчёт уже отправляется — подождите минуту.'], 409);
        }

        ScheduledReportJob::dispatch($report->id, ScheduledReportRun::TRIGGER_MANUAL);

        return response()->json(['message' => 'Отчёт поставлен в очередь на отправку.'], 202);
    }

    public function history(Request $request, int $id): JsonResponse
    {
        $report = $this->find($request, $id);

        $runs = $report->runs()->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => $runs->map(fn (ScheduledReportRun $run) => [
            'id' => $run->id,
            'trigger' => $run->trigger,
            'status' => $run->status,
            'delivery' => $run->delivery,
            'recipients' => $run->recipients ?? [],
            'file_size' => $run->file_size,
            'error' => $run->error,
            'started_at' => $run->started_at,
            'finished_at' => $run->finished_at,
        ])->values()]);
    }

    private function validated(Request $request, Workspace $workspace): array
    {
        $companyId = $workspace->company_id;

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'dashboard_id' => [
                'required',
                'integer',
                Rule::exists('dashboards', 'id')
                    ->where('company_id', $companyId)
                    ->where('workspace_id', $workspace->id),
            ],
            'format' => ['required', Rule::in(DashboardExport::FORMATS)],
            'frequency' => ['required', Rule::in(ScheduledReport::FREQUENCIES)],
            'weekday' => ['required_if:frequency,weekly', 'nullable', 'integer', 'between:1,7'],
            // 0 — последний день месяца; 29–31 нет, потому что их нет в каждом месяце.
            'day_of_month' => ['required_if:frequency,monthly', 'nullable', 'integer', 'between:0,28'],
            'send_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'timezone' => ['required', 'timezone:all'],
            'is_active' => 'sometimes|boolean',

            'recipients' => 'required|array',
            'recipients.users' => 'sometimes|array',
            'recipients.users.*' => [
                'integer',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],
            'recipients.emails' => 'sometimes|array',
            'recipients.emails.*' => 'email:filter|max:255',
        ]);

        $count = count($data['recipients']['users'] ?? []) + count($data['recipients']['emails'] ?? []);

        if ($count === 0) {
            abort(422, 'Укажите хотя бы одного получателя.');
        }

        $max = (int) config('reports.max_recipients');

        if ($max > 0 && $count > $max) {
            abort(422, "Получателей больше, чем разрешено ({$max}).");
        }

        // Лишние для выбранной периодичности поля не храним.
        if ($data['frequency'] !== ScheduledReport::WEEKLY) {
            $data['weekday'] = null;
        }

        if ($data['frequency'] !== ScheduledReport::MONTHLY) {
            $data['day_of_month'] = null;
        }

        return $data;
    }

    private function card(ScheduledReport $report): array
    {
        $recipients = $report->recipients ?? [];

        return [
            'id' => $report->id,
            'workspace_id' => $report->workspace_id,
            'dashboard_id' => $report->dashboard_id,
            'dashboard_name' => $report->dashboard?->name,
            'title' => $report->title,
            'format' => $report->format,
            'frequency' => $report->frequency,
            'weekday' => $report->weekday,
            'day_of_month' => $report->day_of_month,
            'send_time' => $report->send_time,
            'timezone' => $report->timezone,
            'is_active' => $report->is_active,
            'next_run_at' => $report->next_run_at,
            'last_run_at' => $report->last_run_at,
            'last_status' => $report->last_status,
            'consecutive_failures' => $report->consecutive_failures,
            'disabled_reason' => $report->disabled_reason,
            'recipients' => [
                'users' => $recipients['users'] ?? [],
                'emails' => $recipients['emails'] ?? [],
                'user_names' => empty($recipients['users']) ? [] : User::query()
                    ->whereIn('id', $recipients['users'])
                    ->pluck('name', 'id'),
            ],
            'created_at' => $report->created_at,
        ];
    }

    private function find(Request $request, int $id): ScheduledReport
    {
        return ScheduledReport::query()
            ->where('company_id', $request->user()->company_id)
            ->with(['workspace', 'dashboard:id,name'])
            ->findOrFail($id);
    }

    private function findWorkspace(Request $request, int $id): Workspace
    {
        return Workspace::query()
            ->ofCompany($request->user()->company_id)
            ->findOrFail($id);
    }
}
