<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Обучающие примеры для классификатора намерений.
 *
 * Сюда попадают фразы, на которых локальная модель не была уверена и решение
 * приняла языковая модель. Это и есть самый ценный материал для обучения:
 * на уверенных примерах учить нечему, а неуверенные лежат ровно на границе
 * между классами (uncertainty sampling).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intent_samples', function (Blueprint $table) {
            $table->id();

            $table->text('text');

            // Уникальность по тексту через хеш: MySQL не умеет строить
            // уникальный индекс по TEXT без указания длины, а обрезать
            // сообщение ради индекса — значит склеить разные фразы.
            $table->char('text_hash', 64)->unique();

            // Метка от языковой модели. Не истина в последней инстанции —
            // GPT тоже ошибается, поэтому переобучение сверяется с отложенным
            // набором и откатывается при просадке.
            $table->string('label', 20)->index();

            // Что предсказала локальная модель и насколько была уверена.
            // Позволяет отделить «модель промолчала» от «модель ошиблась».
            $table->string('predicted', 20)->nullable();
            $table->float('confidence')->nullable();

            $table->string('source', 20)->default('gpt');

            $table->unsignedBigInteger('chat_id')->nullable()->index();
            $table->unsignedBigInteger('message_id')->nullable()->index();

            $table->boolean('used_in_training')->default(false)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intent_samples');
    }
};
