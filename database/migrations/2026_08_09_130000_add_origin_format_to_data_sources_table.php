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
            $table->string('origin_format', 30)->nullable()->after('connection_type');
        });

        DB::table('data_sources')
            ->whereNotNull('extracted_id')
            ->orderBy('id')
            ->pluck('extracted_id', 'id')
            ->each(function ($extractedId, $sourceId) {
                $format = DB::table('data_source_extractions')
                    ->where('id', $extractedId)
                    ->value('document_type');

                if ($format) {
                    DB::table('data_sources')
                        ->where('id', $sourceId)
                        ->update(['origin_format' => $format]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn('origin_format');
        });
    }
};
