<?php

namespace App\Jobs;

use App\Events\DashboardWidgetChanged;
use App\Events\MessageTasksChanged;
use App\Helpers\Dashboard\DashboardReGenerator;
use App\Helpers\Widget\ReviewWidgetsDashboard;
use App\Models\AiChatTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class DashboardReGeneratorJob implements ShouldQueue
{
    use Queueable;

    public $dashboardId;
    public $chatId;
    public $text;
    public $messageId;
    public $timeout = 600;

    /**
     * Create a new job instance.
     */
    public function __construct($chatId, $dashboardId, $messageId ,$text)
    {
        $this->chatId = $chatId;
        $this->dashboardId = $dashboardId;
        $this->messageId = $messageId;
        $this->text = $text;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        \App\Helpers\Ai\AiUsageContext::set(
            \App\Models\AiChat::query()->whereKey($this->chatId)->value('company_id'),
            $this->chatId,
            $this->messageId,
            're_generate_dashboard'
        );

        try {
        $d = null;

        try {
            $d = new DashboardReGenerator($this->dashboardId, $this->chatId, $this->messageId);

            $d->determineChanges($this->text);
            $d->applyChanges();

            // Явно вызываем шаги, которые раньше были скрыты внутри applyChanges() —
            // так ошибка на любом из них попадает в общий catch ниже с понятным логом,
            // а не проваливается в никуда.
            $d->generateInstruction();
            $d->generatingWidgets();
            $d->reGeneratingWidgets();

            $review = new ReviewWidgetsDashboard($d->newDashboard->id, $d->dataSource->id);

            $task = AiChatTask::query()->create([
                'chat_id' => $this->chatId,
                'message_id' => $this->messageId,
                'task_id' => $d->tasks['review_and_correction_widgets'],
                'status_id' => $d->tasks_statuses['in_progress'],
            ]);
            $task->load(['status', 'task']);

            $d->newDashboard->status = 'reviewing';
            $d->newDashboard->save();

            event(new MessageTasksChanged($d->message, $task, $d->newDashboard->id));
            event(new DashboardWidgetChanged($d->newDashboard));

            $result = $review->handle();

            if (!empty($result['errors'])) {
                $task->status_id = $d->tasks_statuses['failed'];
                $task->save();
                $task->load('status');
                event(new MessageTasksChanged($d->message, $task, $d->newDashboard->id));

                $d->newDashboard->status = 'failed';
                $d->newDashboard->save();
                event(new DashboardWidgetChanged($d->newDashboard));

                \Log::error("DashboardReGeneratorJob: step [review_and_correction_widgets] failed: ".($result['message'] ?? ''));

                throw new RuntimeException($result['message'] ?: 'Step review_and_correction_widgets failed');
            }

            $task->status_id = $d->tasks_statuses['completed'];
            $task->save();
            $task->load('status');

            $d->message->status = 'answered';
            $d->message->save();

            event(new MessageTasksChanged($d->message, $task, $d->newDashboard->id));

            // Финальный статус проставляется НОВОМУ дашборду (тому, который только что
            // прошёл ревью), а не старому $d->dashboard — раньше здесь ошибочно обновлялся
            // старый дашборд, а новый так и оставался в 'reviewing'/'empty'.
            $d->newDashboard->status = 'completed';
            $d->newDashboard->save();
            event(new DashboardWidgetChanged($d->newDashboard));

        } catch (Throwable $e) {
            \Log::error($e->getMessage());
            \Log::error($e->getTraceAsString());

            if ($d && $d->message) {
                $d->message->status = 'failed';
                $d->message->answer = $d->message->answer ?? 'Не удалось обработать запрос. Попробуйте ещё раз.';
                $d->message->save();

                event(new MessageTasksChanged($d->message, null, $d->newDashboard->id ?? null));
            }

            if ($d && $d->newDashboard) {
                $d->newDashboard->status = 'failed';
                $d->newDashboard->save();
                event(new DashboardWidgetChanged($d->newDashboard));
            }

            throw $e;
        }
        } finally {
            // Воркер долгоживущий — контекст обязан сбрасываться.
            \App\Helpers\Ai\AiUsageContext::clear();
        }
    }
}
