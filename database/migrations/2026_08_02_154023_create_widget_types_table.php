<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('widget_types', function (Blueprint $table) {
            $table->id();

            $table->foreignId('widget_id')->constrained('widgets')->cascadeOnDelete();

            $table->string('name');
            $table->string('title')->nullable();
            $table->text('description')->nullable();

            $table->text('scheme')->nullable();
            $table->text('scheme_description')->nullable();

            $table->json('options')->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_ai_selectable')->default(true);
            $table->integer('position')->default(0);

            $table->timestamps();

            $table->unique(['widget_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_types');
    }
};
