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
        Schema::create('dashboard_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('filter_id')->constrained('filters');
            $table->foreignId('filter_connect_setting_id')->constrained('filter_connect_settings');
            $table->foreignId('dashboard_id')->constrained('dashboards');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboard_filters');
    }
};
