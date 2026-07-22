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
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_id')->constrained('ai_chats')->cascadeOnDelete();
            $table->foreignId('type_id')->constrained('data_source_types');
            $table->foreignId('extracted_id')->nullable()->constrained('data_source_extractions');
            $table->enum('connection_type',['local','remote'])->default('local');
            $table->string('name')->nullable();
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('database')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->text('path')->nullable();
            $table->string('version', 20)->nullable();
            $table->json('options')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_sources');
    }
};
