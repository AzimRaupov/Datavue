<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Что агент предложил пользователю в этом ответе.
 *
 * Языковая модель отдаёт два текста: `answer` — человеку, развёрнуто и в
 * markdown, и эти два поля — машине. Смысл в том, что короткая реплика
 * пользователя («давай», «нет») разрешима только по предыдущему ходу, а
 * вытаскивать предложение из markdown-простыни обрезкой последнего
 * предложения — гадание. Агент знает, что он предложил, — пусть и говорит
 * об этом прямо.
 *
 * offer_type — закрытый список: dashboard, export, question, none.
 * Открыть его значит через месяц получить сорок значений, то есть снова прозу.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table) {
            $table->string('offer_type', 20)->nullable()->after('answer');
            $table->text('offer_summary')->nullable()->after('offer_type');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table) {
            $table->dropColumn(['offer_type', 'offer_summary']);
        });
    }
};
