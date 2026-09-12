<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Каждая проверка алерта сохраняет свой результат в CSV — не только на
 * случай срабатывания: файл нужен и чтобы посмотреть, что именно видела
 * платформа при обычной, «спокойной» проверке.
 *
 * Ссылка на скачивание — публичный токен, как у ChatExport: письмо уходит
 * не только тому, у кого есть сессия в приложении, а путь к файлу на диске
 * наружу не отдаётся никогда.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_checker_histories', function (Blueprint $table) {
            $table->string('csv_path')->nullable()->after('payload');
            $table->string('csv_token', 64)->nullable()->unique()->after('csv_path');
            $table->unsignedInteger('csv_row_count')->nullable()->after('csv_token');
        });
    }

    public function down(): void
    {
        Schema::table('alert_checker_histories', function (Blueprint $table) {
            $table->dropColumn(['csv_path', 'csv_token', 'csv_row_count']);
        });
    }
};
