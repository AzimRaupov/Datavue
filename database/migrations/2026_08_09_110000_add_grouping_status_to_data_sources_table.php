<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->enum('grouping_status', [
                'pending',
                'queued',
                'in_progress',
                'completed',
                'failed',
            ])->default('pending')->after('options');

            $table->string('grouping_stage')->nullable()->after('grouping_status');
            $table->text('grouping_message')->nullable()->after('grouping_stage');
        });

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
