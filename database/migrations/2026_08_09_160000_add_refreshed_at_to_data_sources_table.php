<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Когда данные источника обновлялись в последний раз.
 *
 * Файловые источники и Google-таблицы — снимок на момент загрузки. Раз их
 * теперь можно обновлять, пользователю нужно видеть, насколько свежие цифры
 * он смотрит: дашборд, построенный на данных месячной давности, выглядит
 * так же убедительно, как на свежих.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->timestamp('refreshed_at')->nullable()->after('grouping_message');
        });

        // Для существующих источников момент загрузки и есть момент
        // последнего обновления.
        DB::statement('UPDATE data_sources SET refreshed_at = created_at WHERE connection_type = "local"');

        // Группировка может устареть после обновления данных — добавляем
        // соответствующее состояние в перечисление.
        DB::statement("
            ALTER TABLE data_sources
            MODIFY COLUMN grouping_status
            ENUM('pending','queued','in_progress','completed','failed','stale')
            NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        DB::statement("UPDATE data_sources SET grouping_status = 'completed' WHERE grouping_status = 'stale'");

        DB::statement("
            ALTER TABLE data_sources
            MODIFY COLUMN grouping_status
            ENUM('pending','queued','in_progress','completed','failed')
            NOT NULL DEFAULT 'pending'
        ");

        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn('refreshed_at');
        });
    }
};
