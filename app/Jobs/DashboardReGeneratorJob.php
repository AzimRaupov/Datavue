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

    public $history;

    public function __construct($chatId, $dashboardId, $messageId, $text, $history = null)
    {
        $this->chatId = $chatId;
        $this->dashboardId = $dashboardId;
        $this->messageId = $messageId;
        $this->text = $text;
        $this->history = $history;
    }

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

            $d->determineChanges($this->text, $this->history);

            if (empty($d->operations)) {
                \Log::warning('DashboardReGeneratorJob: no operations, dashboard left unchanged', [
                    'dashboard_id' => $this->dashboardId,
                    'message_id' => $this->messageId,
                    'instruction' => $this->text,
                ]);

                \App\Models\IntentSample::reject($this->messageId, 'изменений для дашборда не найдено');

                $d->message->answer = 'Я не понял, что именно нужно изменить на дашборде, и поэтому ничего не трогал. '
                    ."\n\n".'Напишите чуть конкретнее — какой виджет и что с ним сделать. Например: '
                    .'«объедини карточки в один виджет вверху и удали второй виджет с карточками» '
                    .'или «удали виджет «Средний платеж», а его метрику добавь в «Глобальные агрегаты»».';
                $d->message->status = 'answered';
                $d->message->save();

                event(new MessageTasksChanged($d->message, null, null));

                return;
            }

            $d->applyChanges();

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

            \App\Models\IntentSample::confirm($this->messageId);

            event(new MessageTasksChanged($d->message, $task, $d->newDashboard->id));

            $d->newDashboard->status = 'completed';
            $d->newDashboard->save();
            event(new DashboardWidgetChanged($d->newDashboard));

        } catch (Throwable $e) {
            \Log::error($e->getMessage());
            \Log::error($e->getTraceAsString());

            \App\Models\IntentSample::reject($this->messageId, 'перестройка дашборда упала');

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

            \App\Helpers\Ai\AiUsageContext::clear();
        }
    }
}
