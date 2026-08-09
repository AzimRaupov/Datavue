<?php

namespace App\Jobs;

use App\Events\DashboardWidgetChanged;
use App\Events\MessageTasksChanged;
use App\Helpers\Dashboard\DashboardGenerator;
use App\Helpers\Widget\ReviewWidgetsDashboard;
use App\Models\AiChatTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class DashboardGeneratorJob implements ShouldQueue
{
    use Queueable;

    public $chat_id;
    public $message_id;
    public $upload_id;
    public $timeout = 600;
    public $upload;
    public $user_id;
    public $dataSourceId;

    /**
     * Create a new job instance.
     */
    public function __construct($message_id, $chat_id, $user_id, $dataSourceId)
    {
        $this->message_id = $message_id;
        $this->chat_id = $chat_id;
        $this->user_id = $user_id;
        $this->dataSourceId = $dataSourceId;
    }

    public function handle(): void
    {
        \App\Helpers\Ai\AiUsageContext::set(
            \App\Models\AiChat::query()->whereKey($this->chat_id)->value('company_id'),
            $this->chat_id,
            $this->message_id,
            'generate_dashboard'
        );

        try {
            $generator = new DashboardGenerator(
                $this->chat_id,
                $this->message_id,
            );
            $grouping = new \App\Helpers\DataSource\DataSourceGrouping($this->dataSourceId);

            if (!$grouping->load()) {
                $task = AiChatTask::query()->create([
                    'chat_id' => $generator->chat->id,
                    'message_id' => $generator->message->id,
                    'task_id' => $generator->tasks['data_source_grouping'],
                    'status_id' => $generator->tasks_statuses['in_progress'],
                ]);
                $task->load(['status', 'task']);
                event(new MessageTasksChanged($generator->message, $task));

                $grouping->handle();
                $grouping->save();

                $task->status_id = $generator->tasks_statuses['completed'];
                $task->save();
                $task->load('status');
                event(new MessageTasksChanged($generator->message, $task));
            }



            // ---------------------------------------------------------------
            // Шаг 1: determine_data_source_groups
            // ---------------------------------------------------------------
            $task = AiChatTask::query()->create([
                'chat_id' => $generator->chat->id,
                'message_id' => $generator->message->id,
                'task_id' => $generator->tasks['determine_data_source_groups'],
                'status_id' => $generator->tasks_statuses['in_progress'],
            ]);
            $task->load(['status', 'task']);

            $generator->dashboard->status = 'empty';
            $generator->dashboard->save();

            event(new MessageTasksChanged($generator->message, $task));

            $result = $generator->defineGroups();

            if (!empty($result['errors'])) {
                if (!isset($generator->tasks_statuses['failed'])) {
                    \Log::error("DashboardGeneratorJob: task status 'failed' is not configured in tasks_statuses");
                    throw new RuntimeException("Task status 'failed' is not configured.");
                }

                $task->status_id = $generator->tasks_statuses['failed'];
                $task->save();
                $task->load('status');
                event(new MessageTasksChanged($generator->message, $task, $generator->dashboard->id));

                $generator->dashboard->status = 'failed';
                $generator->dashboard->save();
                event(new DashboardWidgetChanged($generator->dashboard));

                \Log::error("DashboardGeneratorJob: step [determine_data_source_groups] failed: ".($result['message'] ?? ''));

                throw new RuntimeException($result['message'] ?: 'Step determine_data_source_groups failed');
            }

            $task->status_id = $generator->tasks_statuses['completed'];
            $task->save();
            $task->load('status');
            event(new MessageTasksChanged($generator->message, $task));



            // ---------------------------------------------------------------
            // Шаг 2: detect_schema_dashboard
            // ---------------------------------------------------------------
            $task = AiChatTask::query()->create([
                'chat_id' => $generator->chat->id,
                'message_id' => $generator->message->id,
                'task_id' => $generator->tasks['detect_schema_dashboard'],
                'status_id' => $generator->tasks_statuses['in_progress'],
            ]);
            $task->load(['status', 'task']);

            $generator->dashboard->status = 'generating_scheme';
            $generator->dashboard->save();

            event(new MessageTasksChanged($generator->message, $task, $generator->dashboard->id));
            event(new DashboardWidgetChanged($generator->dashboard));

            $result = $generator->generateWidgets();

            if (!empty($result['errors'])) {
                if (!isset($generator->tasks_statuses['failed'])) {
                    \Log::error("DashboardGeneratorJob: task status 'failed' is not configured in tasks_statuses");
                    throw new RuntimeException("Task status 'failed' is not configured.");
                }

                $task->status_id = $generator->tasks_statuses['failed'];
                $task->save();
                $task->load('status');
                event(new MessageTasksChanged($generator->message, $task, $generator->dashboard->id));

                $generator->dashboard->status = 'failed';
                $generator->dashboard->save();
                event(new DashboardWidgetChanged($generator->dashboard));

                \Log::error("DashboardGeneratorJob: step [detect_schema_dashboard] failed: ".($result['message'] ?? ''));

                throw new RuntimeException($result['message'] ?: 'Step detect_schema_dashboard failed');
            }

            $task->status_id = $generator->tasks_statuses['completed'];
            $task->save();
            $task->load('status');
            event(new MessageTasksChanged($generator->message, $task, $generator->dashboard->id));


            $task = AiChatTask::query()->create([
                'chat_id' => $generator->chat->id,
                'message_id' => $generator->message->id,
                'task_id' => $generator->tasks['generate_widgets_dashboard'],
                'status_id' => $generator->tasks_statuses['in_progress'],
            ]);
            $task->load(['status', 'task']);

            $generator->dashboard->status = 'generating_widgets';
            $generator->dashboard->save();

            event(new MessageTasksChanged($generator->message, $task, $generator->dashboard->id));
            event(new DashboardWidgetChanged($generator->dashboard));

            $result = $generator->generateContentToWidgets(
                function ($widget, array $widgetResult, int $index, int $total) use ($generator) {
                    // $widget->status уже проставлен в generateContentWidget ('active'/'failed'),
                    // здесь только уведомляем фронт, что конкретный виджет готов.
                    event(new DashboardWidgetChanged($generator->dashboard));

                    if (!empty($widgetResult['errors'])) {
                        \Log::error(sprintf(
                            'DashboardGeneratorJob: widget #%d (%d/%d) failed: %s',
                            $widget->id,
                            $index + 1,
                            $total,
                            $widgetResult['message'] ?? ''
                        ));
                    }
                }
            );

            if (!empty($result['errors'])) {
                if (!isset($generator->tasks_statuses['failed'])) {
                    \Log::error("DashboardGeneratorJob: task status 'failed' is not configured in tasks_statuses");
                    throw new RuntimeException("Task status 'failed' is not configured.");
                }

                $task->status_id = $generator->tasks_statuses['failed'];
                $task->save();
                $task->load('status');
                event(new MessageTasksChanged($generator->message, $task, $generator->dashboard->id));

                $generator->dashboard->status = 'failed';
                $generator->dashboard->save();
                event(new DashboardWidgetChanged($generator->dashboard));

                \Log::error("DashboardGeneratorJob: step [generate_widgets_dashboard] failed: ".($result['message'] ?? ''));

                throw new RuntimeException($result['message'] ?: 'Step generate_widgets_dashboard failed');
            }

            // Отказ отдельных виджетов не проваливает весь дашборд — они уже помечены 'failed'
            // и будут переданы на исправление в ReviewWidgetsDashboard ниже.
            if (!empty($result['failed'])) {
                \Log::warning(sprintf(
                    'DashboardGeneratorJob: %d/%d widgets failed to generate, continuing to review step',
                    $result['failed'],
                    $result['total'] ?? 0
                ));
            }

            $task->status_id = $generator->tasks_statuses['completed'];
            $task->save();
            $task->load('status');
            event(new MessageTasksChanged($generator->message, $task, $generator->dashboard->id));

            $generator->dashboard->status = 'reviewing';
            $generator->dashboard->save();
            event(new DashboardWidgetChanged($generator->dashboard));

            // ---------------------------------------------------------------
            // Шаг 4: review_and_correction_widgets
            // ---------------------------------------------------------------
            $review = new ReviewWidgetsDashboard($generator->dashboard->id, $this->dataSourceId);

            $task = AiChatTask::query()->create([
                'chat_id' => $generator->chat->id,
                'message_id' => $generator->message->id,
                'task_id' => $generator->tasks['review_and_correction_widgets'],
                'status_id' => $generator->tasks_statuses['in_progress'],
            ]);
            $task->load(['status', 'task']);

            $generator->dashboard->status = 'reviewing';
            $generator->dashboard->save();

            event(new MessageTasksChanged($generator->message, $task, $generator->dashboard->id));
            event(new DashboardWidgetChanged($generator->dashboard));

            $result = $review->handle();

            if (!empty($result['errors'])) {
                if (!isset($generator->tasks_statuses['failed'])) {
                    \Log::error("DashboardGeneratorJob: task status 'failed' is not configured in tasks_statuses");
                    throw new RuntimeException("Task status 'failed' is not configured.");
                }

                $task->status_id = $generator->tasks_statuses['failed'];
                $task->save();
                $task->load('status');
                event(new MessageTasksChanged($generator->message, $task, $generator->dashboard->id));

                $generator->dashboard->status = 'failed';
                $generator->dashboard->save();
                event(new DashboardWidgetChanged($generator->dashboard));

                \Log::error("DashboardGeneratorJob: step [review_and_correction_widgets] failed: ".($result['message'] ?? ''));

                throw new RuntimeException($result['message'] ?: 'Step review_and_correction_widgets failed');
            }

            $task->status_id = $generator->tasks_statuses['completed'];
            $task->save();
            $task->load('status');
            $generator->message->status = "answered";
            $generator->message->save();

            event(new MessageTasksChanged($generator->message, $task, $generator->dashboard->id));

            $generator->dashboard->status = 'completed';
            $generator->dashboard->save();
            event(new DashboardWidgetChanged($generator->dashboard));

        } catch (Throwable $e) {
            \Log::error($e->getMessage());
            \Log::error($e->getTraceAsString());

            if (isset($generator) && $generator->message) {
                $generator->message->status = "failed";
                $generator->message->answer = $generator->message->answer
                    ?? 'Не удалось сгенерировать дашборд. Попробуйте ещё раз.';
                $generator->message->save();

                event(new MessageTasksChanged($generator->message, null, $generator->dashboard->id ?? null));
            }

            if (isset($generator) && $generator->dashboard) {
                $generator->dashboard->status = 'failed';
                $generator->dashboard->save();
                event(new DashboardWidgetChanged($generator->dashboard));
            }

            throw $e;
        } finally {
            // Воркер живёт долго: не сбросив контекст, следующая задача
            // записала бы расход на предыдущую компанию.
            \App\Helpers\Ai\AiUsageContext::clear();
        }
    }
}
