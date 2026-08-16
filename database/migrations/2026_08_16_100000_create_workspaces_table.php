<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Рабочее пространство — то, что человек заводит сам и складывает в него работу.
 *
 * До этого «пространством» по факту служил чат: дашборды принадлежали ему,
 * а собранные руками не принадлежали ничему и находились только через общий
 * список. Группировать их по источнику данных тоже неправильно — на одной базе
 * живут и продажи, и склад, и кадры, и это разные задачи разных людей.
 *
 * Поэтому пространство — отдельная сущность: у него есть имя, источник данных
 * и внутри него дашборды со своим разговором.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Источник у пространства один: дашборды внутри считают по одним
            // и тем же таблицам, и агент в разговоре смотрит на них же.
            // Пустым остаётся только у пространства, чей источник удалили, —
            // терять из-за этого сами дашборды неправильно.
            $table->foreignId('data_source_id')
                ->nullable()
                ->constrained('data_sources')
                ->nullOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
