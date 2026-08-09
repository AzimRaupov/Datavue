<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал расхода токенов ИИ.
 *
 * До сих пор расход писался только в ai_chat_messages.tokens_used, то есть
 * учитывались лишь ответы в чате. Всё остальное — группировка схемы, подбор
 * вариантов дашбордов, генерация и починка виджетов — тратило деньги
 * бесследно. Компания не видела расхода, потолка не было.
 *
 * Журнал пишется из AIService — единственного места, через которое проходят
 * все обращения к модели, поэтому ни один вызов не может пройти мимо учёта.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Чат и сообщение известны не всегда: группировка таблиц и подбор
            // вариантов дашбордов выполняются вне чата.
            $table->foreignId('chat_id')->nullable()->constrained('ai_chats')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('ai_chat_messages')->nullOnDelete();

            // Что именно делали: define_task, generate_dashboard, grouping,
            // suggestions и т.д. — чтобы видеть, на что уходит бюджет.
            $table->string('operation', 60)->nullable();
            $table->string('model', 60)->nullable();

            $table->unsignedInteger('tokens')->default(0);

            $table->timestamps();

            // Основной запрос — сумма за период по компании.
            $table->index(['company_id', 'created_at']);
        });

        Schema::table('companies', function (Blueprint $table) {
            // Месячный потолок расхода. NULL — без ограничений.
            $table->unsignedBigInteger('ai_token_limit')->nullable()->after('is_active');
        });

        // Перенос уже накопленного расхода из сообщений, чтобы статистика
        // не начиналась с нуля и прошлые траты были видны.
        DB::statement('
            INSERT INTO ai_usage_logs (company_id, chat_id, message_id, operation, tokens, created_at, updated_at)
            SELECT c.company_id, m.chat_id, m.id, "chat_message", m.tokens_used, m.created_at, m.created_at
            FROM ai_chat_messages m
            JOIN ai_chats c ON c.id = m.chat_id
            WHERE m.tokens_used > 0
        ');
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('ai_token_limit');
        });

        Schema::dropIfExists('ai_usage_logs');
    }
};
