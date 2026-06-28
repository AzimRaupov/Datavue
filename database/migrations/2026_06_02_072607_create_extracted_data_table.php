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
        Schema::create('extracted_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->nullable();
            $table->foreignId('chat_id')->nullable()->constrained('ai_chats')->nullOnDelete();;
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type')->nullable();
            $table->string('data_path')->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->enum('status',['success','failed'])->default('success');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('extracted_data');
    }
};
