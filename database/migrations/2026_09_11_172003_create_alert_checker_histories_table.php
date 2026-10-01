<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_checker_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('alerts')->cascadeOnDelete();

            $table->dateTime('checking_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->enum('status', ['ok', 'triggered', 'error']);

            $table->string('value')->nullable();
            $table->unsignedInteger('matched_rows')->nullable();

            $table->json('payload')->nullable();

            $table->text('message')->nullable();
            $table->text('error')->nullable();

            $table->enum('trigger_source', ['schedule', 'manual'])->default('schedule');

            $table->boolean('notified')->default(false);
            $table->dateTime('notified_at')->nullable();
            $table->json('recipients')->nullable();
            $table->text('notify_error')->nullable();

            $table->timestamps();

            $table->index(['alert_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_checker_histories');
    }
};
