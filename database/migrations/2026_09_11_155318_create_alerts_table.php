<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Алерт — сохранённая проверка над источником рабочего пространства: раз
 * в заданный интервал платформа выполняет её сама и, если условие выполнилось,
 * шлёт письмо. ИИ алерты не пишет — условие задаёт человек: метриками через
 * конструктор, сырым SQL или Python (по образцу виджетов).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('data_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // Как задано условие. 'builder' собирает SQL из настроек тем же
            // WidgetQueryComposer, что и виджеты; 'sql' — свой SELECT под
            // ReadOnlySqlGuard; 'python' — тело main() под тем же контрактом,
            // что WidgetCodeRun.
            $table->enum('mode', ['builder', 'sql', 'python'])->default('builder');

            $table->json('builder')->nullable();
            $table->longText('query')->nullable();
            $table->longText('code')->nullable();

            // Как результат SQL-запроса превращается в «сработало/нет»:
            // {kind: rows|value, op, threshold, column, on_empty}. У режима
            // python условие решает сам код — здесь пусто.
            $table->json('condition')->nullable();

            $table->unsignedInteger('interval_minutes')->default(60);
            $table->boolean('is_active')->default(true);

            // Текущее состояние — то, что видно в списке без похода в историю.
            $table->enum('state', ['unknown', 'ok', 'firing', 'error'])->default('unknown');

            $table->dateTime('next_check_at')->nullable();
            $table->dateTime('last_checked_at')->nullable();
            $table->dateTime('last_triggered_at')->nullable();
            $table->dateTime('last_notified_at')->nullable();
            $table->dateTime('last_error_notified_at')->nullable();

            // Для авто-отключения алерта, который стабильно падает.
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->text('disabled_reason')->nullable();

            // {users: [id, ...], emails: ["...", ...]}
            $table->json('recipients')->nullable();

            $table->unsignedInteger('repeat_after_minutes')->default(1440);
            $table->boolean('notify_on_resolve')->default(true);

            // Пустой результат — не заданное по умолчанию поведение,
            // а явный выбор автора при сохранении условия.
            $table->timestamps();

            // По этому индексу планировщик выбирает готовые к проверке.
            $table->index(['is_active', 'next_check_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
