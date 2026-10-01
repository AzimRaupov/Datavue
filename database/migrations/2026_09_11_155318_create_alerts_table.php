<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('data_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->enum('mode', ['builder', 'sql', 'python'])->default('builder');

            $table->json('builder')->nullable();
            $table->longText('query')->nullable();
            $table->longText('code')->nullable();

            $table->json('condition')->nullable();

            $table->unsignedInteger('interval_minutes')->default(60);
            $table->boolean('is_active')->default(true);

            $table->enum('state', ['unknown', 'ok', 'firing', 'error'])->default('unknown');

            $table->dateTime('next_check_at')->nullable();
            $table->dateTime('last_checked_at')->nullable();
            $table->dateTime('last_triggered_at')->nullable();
            $table->dateTime('last_notified_at')->nullable();
            $table->dateTime('last_error_notified_at')->nullable();

            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->text('disabled_reason')->nullable();

            $table->json('recipients')->nullable();

            $table->unsignedInteger('repeat_after_minutes')->default(1440);
            $table->boolean('notify_on_resolve')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'next_check_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
