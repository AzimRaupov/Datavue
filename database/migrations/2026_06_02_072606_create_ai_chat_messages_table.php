<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('ai_chats')->nullOnDelete();
            $table->foreignId('file_id')->nullable();
            $table->text('message');
            $table->longText('answer')->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            $table->json('tool_results')->nullable();
            $table->enum('status', [
                'send',        // Запрос отправлен
                'generating',  // Генерация ответа
                'answered',    // Ответ успешно сформирован и отправлен
                'failed',      // Ошибка при обработке
            ])->default('send');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
    }
};
