<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_suggestions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('data_source_id')
                ->constrained('data_sources')
                ->cascadeOnDelete();

            $table->string('title');

            $table->text('prompt');

            $table->text('description')->nullable();

            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->index(['data_source_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_suggestions');
    }
};
