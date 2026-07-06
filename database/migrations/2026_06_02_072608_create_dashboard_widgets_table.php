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
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_id')->nullable()->constrained('dashboards')->nullOnDelete();
            $table->foreignId('widget_id')->nullable()->constrained('widgets')->nullOnDelete();
            $table->text('instruction');
            $table->string('title');
            $table->integer('position')->default(0);
            $table->json('tables')->nullable();
            $table->string('code_path',300)->nullable();
            $table->enum('status', ['draft', 'active', 'inactive', 'failed'])->default('draft');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
    }
};
