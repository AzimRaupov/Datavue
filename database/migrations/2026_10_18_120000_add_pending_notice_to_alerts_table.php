<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            // Уведомление, которое не удалось отправить (почта была недоступна): повторяется, пока не уйдёт.
            // resolved — «вернулся в норму», error — «ошибка проверки», disabled — «алерт отключён».
            $table->string('pending_notice', 16)->nullable()->after('last_error_notified_at');
            $table->unsignedBigInteger('pending_check_id')->nullable()->after('pending_notice');
            $table->unsignedSmallInteger('pending_attempts')->default(0)->after('pending_check_id');
            $table->dateTime('pending_retry_at')->nullable()->after('pending_attempts');

            $table->index('pending_retry_at');
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropIndex(['pending_retry_at']);
            $table->dropColumn(['pending_notice', 'pending_check_id', 'pending_attempts', 'pending_retry_at']);
        });
    }
};
