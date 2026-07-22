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
        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chat_id')->nullable()->constrained('ai_chats')->nullOnDelete();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['generating_scheme', 'generating_widgets', 'completed','empty'])->default('empty');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboards');
    }
};
