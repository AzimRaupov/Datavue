<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_exports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('dashboard_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('token', 64)->unique();
            $table->string('format', 16);
            $table->string('status', 16)->default('pending');
            $table->string('file_name')->nullable();
            $table->string('path', 1024)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_exports');
    }
};
