<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Подтверждён ли обучающий пример делом.
 *
 * Метка бралась из решения маршрутизатора — то есть из мнения GPT в момент,
 * когда ещё ничего не произошло. Но решение бывает неверным: модель отправляет
 * вопрос на перестройку дашборда, генератор разводит руками «не понял, что
 * менять», а пример с меткой dashboard уже лежит в базе и учит локальную модель
 * повторять ту же ошибку. Цикл дообучения начинает закреплять промахи учителя —
 * ровно то, от чего он должен защищать.
 *
 * Теперь пример живёт в состоянии pending, пока исполнитель не подтвердит его
 * делом: дашборд перестроен, файл создан, агент ответил. Не подтвердил —
 * rejected, и в обучение такой пример не попадает.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intent_samples', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->index()->after('source');
            $table->string('reject_reason')->nullable()->after('status');
        });

        // Примеры, накопленные до появления проверки, уже прошли через живые
        // ответы — считаем их подтверждёнными, иначе потеряем всё собранное.
        \Illuminate\Support\Facades\DB::table('intent_samples')->update(['status' => 'confirmed']);
    }

    public function down(): void
    {
        Schema::table('intent_samples', function (Blueprint $table) {
            $table->dropColumn(['status', 'reject_reason']);
        });
    }
};
