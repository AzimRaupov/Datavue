<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Состояние группировки таблиц источника.
 *
 * Группировка идёт в фоне и занимает минуты, поэтому её ход нужно где-то
 * хранить: сокет доносит прогресс только тем, кто в этот момент смотрит на
 * страницу, а вернувшийся через час пользователь должен увидеть актуальное
 * состояние, а не пустоту.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->enum('grouping_status', [
                'pending',      // ещё не запускали
                'queued',       // поставлена в очередь
                'in_progress',  // выполняется
                'completed',
                'failed',
            ])->default('pending')->after('options');

            // Подпись текущего (или последнего) этапа — её же показывает мастер.
            $table->string('grouping_stage')->nullable()->after('grouping_status');
            $table->text('grouping_message')->nullable()->after('grouping_stage');
        });

        // Источники, уже сгруппированные до появления этих колонок, не должны
        // выглядеть как «ещё не запускали».
        Schema::hasTable('data_source_groups') && \DB::table('data_sources')
            ->whereIn('id', \DB::table('data_source_groups')->distinct()->pluck('data_source_id'))
            ->update(['grouping_status' => 'completed']);
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn(['grouping_status', 'grouping_stage', 'grouping_message']);
        });
    }
};
