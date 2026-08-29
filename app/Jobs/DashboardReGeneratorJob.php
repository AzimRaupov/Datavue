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
     * Последние сообщения чата (message/answer/offer_type/offer_summary), собранные
     * RouterTask ещё до классификации. Нужны determineChanges(), чтобы разрешить
     * короткое подтверждение («давай») против предложения, которое агент сделал
     * предыдущим ходом, — без истории оно долетает до промпта голым текстом,
     * никак не связанным с планом, который пользователь только что одобрил.
     */
    public $history;

    /**
     * Create a new job instance.
     */
    public function __construct($chatId, $dashboardId, $messageId, $text, $history = null)
    {
        $this->chatId = $chatId;
        $this->dashboardId = $dashboardId;
        $this->messageId = $messageId;
        $this->text = $text;
        $this->history = $history;
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

            $d->determineChanges($this->text, $this->history);

            // Пустой список операций означает, что модель не поняла запрос.
            // Раньше работа шла дальше: создавался новый дашборд — точная копия
            // старого, — все задачи отмечались выполненными, и пользователь
            // видел «готово» при неизменном экране. Честный отказ полезнее
            // молчаливого дубля, к тому же он не плодит копии дашбордов.
            if (empty($d->operations)) {
                \Log::warning('DashboardReGeneratorJob: no operations, dashboard left unchanged', [
                    'dashboard_id' => $this->dashboardId,
                    'message_id' => $this->messageId,
                    'instruction' => $this->text,
                ]);

                // Маршрутизатор счёл это командой изменить дашборд, а менять
                // оказалось нечего. Учить локальную модель такому примеру
                // нельзя — она переняла бы чужую ошибку.
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

            // Дашборд действительно перестроен — маршрут подтверждён.
            \App\Models\IntentSample::confirm($this->messageId);

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
            // Воркер долгоживущий — контекст обязан сбрасываться.
            \App\Helpers\Ai\AiUsageContext::clear();
        }
    }
}
