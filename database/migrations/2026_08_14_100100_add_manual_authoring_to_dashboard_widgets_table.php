<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Виджет, код которого пишет человек, а не модель.
 *
 * Код ИИ-виджета лежит файлом, а в базе только code_path. Для ручного виджета
 * этого мало: форму редактирования нужно чем-то заполнять, а сломанную правку —
 * откатывать. Поэтому исходником правды становится колонка code, а файл по
 * code_path материализуется из неё при сохранении — путь запуска остаётся
 * общим для ручных и сгенерированных виджетов.
 *
 * Колонка instruction намеренно НЕ делается nullable: менять существующую
 * колонку значит пересоздавать таблицу вместе с внешними ключами, а ради
 * ручного виджета достаточно писать в неё пустую строку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->longText('code')->nullable()->after('code_path');

            // Предыдущая версия кода — чтобы вернуть виджет к рабочему
            // состоянию, не переписывая его заново.
            $table->longText('code_previous')->nullable()->after('code');

            // 'python' — тело main(); 'sql' — задел на query_spec.
            $table->string('content_mode', 16)->default('python')->after('code_previous');

            $table->string('origin', 16)->default('ai')->after('content_mode');

            // Последний результат прогона: почему виджет отмечен сломанным.
            $table->text('last_error')->nullable()->after('origin');
            $table->timestamp('last_run_at')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->dropColumn([
                'code',
                'code_previous',
                'content_mode',
                'origin',
                'last_error',
                'last_run_at',
            ]);
        });
    }
};
