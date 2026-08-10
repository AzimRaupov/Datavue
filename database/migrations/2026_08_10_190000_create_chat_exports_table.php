<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Файлы, которые агент сформировал по просьбе пользователя в чате
 * («посчитай топ-10 клиентов и сохрани в csv»).
 *
 * Файл лежит вне public/, а скачивается по токену: путь на диске содержит
 * id компании и чата, и отдавать его наружу нельзя.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_exports', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('chat_id')->index();
            $table->unsignedBigInteger('message_id')->nullable()->index();

            // Публичная часть ссылки. Длинная и случайная — файл содержит
            // данные компании, и подобрать адрес перебором быть не должно.
            $table->string('token', 64)->unique();

            $table->string('format', 10);
            $table->string('title')->nullable();
            $table->string('file_name');

            // Абсолютный путь на диске воркера.
            $table->string('path', 1024);

            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedBigInteger('rows_count')->default(0);
            $table->unsignedBigInteger('total_rows')->default(0);
            $table->boolean('truncated')->default(false);

            $table->json('columns')->nullable();

            // Сгенерированный Python-код: и для отладки, и чтобы повторить
            // выгрузку на свежих данных, не обращаясь к модели заново.
            $table->longText('code')->nullable();

            $table->string('status', 20)->default('ready');
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_exports');
    }
};
