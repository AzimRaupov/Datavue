<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_reports', function (Blueprint $table) {
            // Сколько быстрых повторов уже сделано для текущего срабатывания (см. ScheduledReportJob).
            $table->unsignedTinyInteger('retry_attempts')->default(0)->after('consecutive_failures');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_reports', function (Blueprint $table) {
            $table->dropColumn('retry_attempts');
        });
    }
};
