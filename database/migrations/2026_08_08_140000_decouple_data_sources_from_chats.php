<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

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

            $table->foreignId('created_by')
                ->nullable()
                ->after('company_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('ai_chats', function (Blueprint $table) {
            $table->foreignId('data_source_id')
                ->nullable()
                ->after('company_id')
                ->constrained('data_sources')
                ->nullOnDelete();
        });

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
