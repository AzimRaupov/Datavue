<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
