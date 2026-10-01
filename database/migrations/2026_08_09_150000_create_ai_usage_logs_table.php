<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->foreignId('chat_id')->nullable()->constrained('ai_chats')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('ai_chat_messages')->nullOnDelete();

            $table->string('operation', 60)->nullable();
            $table->string('model', 60)->nullable();

            $table->unsignedInteger('tokens')->default(0);

            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });

        Schema::table('companies', function (Blueprint $table) {

            $table->unsignedBigInteger('ai_token_limit')->nullable()->after('is_active');
        });

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
