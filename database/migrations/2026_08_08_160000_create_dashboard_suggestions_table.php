<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Готовые варианты дашбордов («с чего начать») для источника данных.
 *
 * Строятся по смысловым группам таблиц (data_source_groups), поэтому привязаны
 * к ИСТОЧНИКУ, а не к чату: на одном источнике варианты одни и те же, и второму
 * чату незачем платить за их повторную генерацию. В чате они показываются
 * через связь чат → источник.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_suggestions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('data_source_id')
                ->constrained('data_sources')
                ->cascadeOnDelete();

            // Короткий заголовок для кнопки в чате.
            $table->string('title');

            // Текст, который уходит агенту как сообщение пользователя,
            // когда он выбирает вариант.
            $table->text('prompt');

            // Пояснение под заголовком: что покажет этот дашборд.
            $table->text('description')->nullable();

            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->index(['data_source_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_suggestions');
    }
};
