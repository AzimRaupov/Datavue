<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Источник данных перестаёт быть частью чата и становится самостоятельной
 * сущностью компании.
 *
 * Было: чат создавался ВМЕСТЕ с источником — один чат, один источник, и наоборот.
 * Переподключить ту же базу для второго вопроса было невозможно: приходилось
 * заново загружать файл и заново ждать разбора схемы.
 *
 * Стало: компания сначала подключает источник, а потом заводит на нём сколько
 * угодно чатов. Отсюда две правки схемы:
 *  - data_sources.chat_id больше не обязателен (и не сносит источник вместе с
 *    чатом) — колонка остаётся только ради старых записей;
 *  - ai_chats.data_source_id — новая, основная связь «чат принадлежит источнику».
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Источник больше не привязан к чату жёстко.
        //    Сначала снимаем внешний ключ: под ним колонку не изменить,
        //    да и cascadeOnDelete теперь вреден — удаление чата не должно
        //    уносить с собой источник, на котором работают другие чаты.
        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropForeign(['chat_id']);
        });

        Schema::table('data_sources', function (Blueprint $table) {
            $table->unsignedBigInteger('chat_id')->nullable()->change();
        });

        Schema::table('data_sources', function (Blueprint $table) {
            $table->foreign('chat_id')
                ->references('id')
                ->on('ai_chats')
                ->nullOnDelete();

            // Кто подключил источник — показывается в списке источников.
            $table->foreignId('created_by')
                ->nullable()
                ->after('company_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        // 2. Основная связь: чат заводится НА источнике.
        Schema::table('ai_chats', function (Blueprint $table) {
            $table->foreignId('data_source_id')
                ->nullable()
                ->after('company_id')
                ->constrained('data_sources')
                ->nullOnDelete();
        });

        // 3. Переносим существующие связи в новую колонку, чтобы старые чаты
        //    продолжали находить свой источник уже по новому пути.
        DB::table('ai_chats')->orderBy('id')->chunkById(200, function ($chats) {
            foreach ($chats as $chat) {
                $sourceId = DB::table('data_sources')
                    ->where('chat_id', $chat->id)
                    ->orderBy('id')
                    ->value('id');

                if ($sourceId) {
                    DB::table('ai_chats')
                        ->where('id', $chat->id)
                        ->update(['data_source_id' => $sourceId]);
                }
            }
        });

        // 4. Источникам без имени даём его сейчас — в списке источников
        //    пустая карточка выглядит как ошибка. Идём построчно, а не одним
        //    UPDATE с CONCAT: функции склейки строк у MySQL и SQLite разные,
        //    а тесты гоняются на SQLite.
        DB::table('data_sources')
            ->where(fn ($query) => $query->whereNull('name')->orWhere('name', ''))
            ->orderBy('id')
            ->pluck('id')
            ->each(fn ($id) => DB::table('data_sources')
                ->where('id', $id)
                ->update(['name' => 'Источник #' . $id]));
    }

    public function down(): void
    {
        Schema::table('ai_chats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('data_source_id');
        });

        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropForeign(['chat_id']);
        });

        // Возвращаем прежнюю жёсткую связь. Источники, не привязанные ни к
        // одному чату, при откате удаляются — иначе NOT NULL не наложить.
        DB::table('data_sources')->whereNull('chat_id')->delete();

        Schema::table('data_sources', function (Blueprint $table) {
            $table->unsignedBigInteger('chat_id')->nullable(false)->change();
        });

        Schema::table('data_sources', function (Blueprint $table) {
            $table->foreign('chat_id')
                ->references('id')
                ->on('ai_chats')
                ->cascadeOnDelete();
        });
    }
};
