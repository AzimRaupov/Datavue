<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Какой вариант отрисовки внутри семейства выбрал ИИ для этого виджета.
     *
     * nullable: если тип не указан или ИИ вернул несуществующий, фронт берёт
     * тип по умолчанию из семейства — виджет всё равно отрисуется.
     */
    public function up(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->foreignId('widget_type_id')
                ->nullable()
                ->after('widget_id')
                ->constrained('widget_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('widget_type_id');
        });
    }
};
