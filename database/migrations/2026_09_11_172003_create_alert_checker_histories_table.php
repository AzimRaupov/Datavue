<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Одна строка — одна проверка алерта: когда началась, чем закончилась,
 * что увидела и ушло ли письмо. Пишется ВСЕГДА, включая ошибку, — иначе
 * сломанная проверка выглядела бы как молчаливое бездействие.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_checker_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('alerts')->cascadeOnDelete();

            $table->dateTime('checking_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->enum('status', ['ok', 'triggered', 'error']);

            // Значение, с которым сравнивали порог (kind=value), как текст —
            // под ним могут быть и числа, и даты.
            $table->string('value')->nullable();
            $table->unsignedInteger('matched_rows')->nullable();

            // Образец строк результата — тот же, что уходит в письмо.
            $table->json('payload')->nullable();
            // Пояснение от Python-условия (поле "message" в его JSON-выводе) —
            // отдельно от error: это не сбой проверки, а комментарий автора кода.
            $table->text('message')->nullable();
            $table->text('error')->nullable();

            $table->enum('trigger_source', ['schedule', 'manual'])->default('schedule');

            $table->boolean('notified')->default(false);
            $table->dateTime('notified_at')->nullable();
            $table->json('recipients')->nullable();
            $table->text('notify_error')->nullable();

            $table->timestamps();

            $table->index(['alert_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_checker_histories');
    }
};
