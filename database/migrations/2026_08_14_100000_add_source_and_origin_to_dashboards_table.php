<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Дашборд, собранный руками, а не пайплайном ИИ.
 *
 * Ключевая проблема: источник данных виджета до сих пор искался через чат
 * (dashboard -> chat -> resolveDataSource). У ручного дашборда чата нет, и
 * считать виджету было бы не по чему. Поэтому источник теперь можно указать
 * на самом дашборде; у старых дашбордов поле пустое и работает прежний путь
 * через чат — генерация от этого не меняется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboards', function (Blueprint $table) {
            $table->foreignId('data_source_id')
                ->nullable()
                ->after('chat_id')
                ->constrained('data_sources')
                ->nullOnDelete();

            $table->string('origin', 16)->default('ai')->after('status');

            $table->foreignId('created_by')
                ->nullable()
                ->after('company_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dashboards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('data_source_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('origin');
        });
    }
};
