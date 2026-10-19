<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            // Номер текущего этапа группировки (0..3). Нужен, чтобы клиент, который
            // не получил событие из сокета, мог восстановить ход по обычному запросу.
            $table->unsignedTinyInteger('grouping_step')->default(0)->after('grouping_stage');
        });
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn('grouping_step');
        });
    }
};
