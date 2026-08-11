<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Тип виджета — конкретный вариант отрисовки внутри семейства.
     *
     * Семейство ("widgets") задаёт форму данных: например pie — это labels + series.
     * Тип задаёт, как эту форму нарисовать: сплошной круг, кольцо, полукольцо.
     * Раньше каждый вариант был отдельным виджетом (pie-chart и donut-chart
     * отличались одной строкой конфига), из-за чего ИИ выбирал между ними
     * вслепую, а каталог разрастался копиями.
     */
    public function up(): void
    {
        Schema::create('widget_types', function (Blueprint $table) {
            $table->id();

            $table->foreignId('widget_id')->constrained('widgets')->cascadeOnDelete();

            $table->string('name');
            $table->string('title')->nullable();
            $table->text('description')->nullable();

            /*
            | scheme/scheme_description — переопределение формы данных семейства.
            | Заполняются только у типов, которым нужны свои поля (bubble требует
            | третье число на точку, polar-area — плоский список значений).
            | Пустые — тип наследует форму семейства.
            */
            $table->text('scheme')->nullable();
            $table->text('scheme_description')->nullable();

            // Параметры отрисовки для фронта: тип графика ApexCharts, stacked и т.п.
            $table->json('options')->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_ai_selectable')->default(true);
            $table->integer('position')->default(0);

            $table->timestamps();

            // Имя уникально внутри семейства, а не глобально: "bar" как тип
            // семейства bar и "bar" как тип combo — разные вещи.
            $table->unique(['widget_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_types');
    }
};
