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
       Schema::create('data_source_tables', function (Blueprint $table) {
           $table->id();
           $table->foreignId('data_source_id')->constrained('data_sources')->cascadeOnDelete();
           $table->foreignId('data_source_group_id')->nullable()->constrained('data_source_groups')->nullOnDelete();
           $table->string('name');
           $table->text('description')->nullable();
           $table->string('role')->nullable();
           $table->timestamps();
       });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_source_tables');
    }
};
