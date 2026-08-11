<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Исходный формат источника — то, что пользователь на самом деле подключил.
 *
 * Загруженные csv/xlsx и Google-таблицы разбираются в DuckDB, поэтому в
 * type_id у них лежит duckdb — он нужен, чтобы знать, каким провайдером
 * выполнять запросы. Но показывать «duckdb» в списке источников неправильно:
 * пользователь загружал CSV и ожидает увидеть CSV.
 *
 * Эта колонка хранит исходный формат отдельно от технического типа, поэтому
 * маршрутизация запросов не меняется — меняется только подпись в интерфейсе.
 * Для внешних баз остаётся NULL: там исходный формат и есть тип (MySQL,
 * PostgreSQL), брать его надо из справочника провайдеров.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->string('origin_format', 30)->nullable()->after('connection_type');
        });

        // Для уже существующих источников формат восстанавливается из записи
        // о разборе: document_type там — это расширение исходного файла.
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
