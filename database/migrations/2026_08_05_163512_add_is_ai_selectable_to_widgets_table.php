<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            // Позволяет держать виджет в каталоге (для будущей доработки фронта),
            // но не предлагать его ИИ при выборе типа виджета, пока он не готов
            // к использованию (например 'map' — фронт под него ещё не подключён).
            $table->boolean('is_ai_selectable')->default(true)->after('scheme_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            $table->dropColumn('is_ai_selectable');
        });
    }
};
