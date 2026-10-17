<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');

            // Что отправлять: pdf или docx (см. DashboardExport::FORMATS).
            $table->string('format', 16)->default('pdf');

            // Когда: daily — каждый день; weekly — по дню недели (1 = понедельник … 7 = воскресенье);
            // monthly — по числу месяца (1–28), 0 — последний день месяца.
            $table->string('frequency', 16)->default('weekly');
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->string('send_time', 5)->default('09:00');
            $table->string('timezone', 64)->default('UTC');

            $table->json('recipients')->nullable();

            $table->boolean('is_active')->default(true);
            $table->dateTime('next_run_at')->nullable();
            $table->dateTime('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->text('disabled_reason')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'next_run_at']);
        });

        Schema::create('scheduled_report_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_report_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 16)->default('schedule');
            $table->string('status', 16)->default('running');
            $table->string('delivery', 16)->nullable();
            $table->json('recipients')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['scheduled_report_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_report_runs');
        Schema::dropIfExists('scheduled_reports');
    }
};
