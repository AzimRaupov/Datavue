<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_exports', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('chat_id')->index();
            $table->unsignedBigInteger('message_id')->nullable()->index();

            $table->string('token', 64)->unique();

            $table->string('format', 10);
            $table->string('title')->nullable();
            $table->string('file_name');

            $table->string('path', 1024);

            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedBigInteger('rows_count')->default(0);
            $table->unsignedBigInteger('total_rows')->default(0);
            $table->boolean('truncated')->default(false);

            $table->json('columns')->nullable();

            $table->longText('code')->nullable();

            $table->string('status', 20)->default('ready');
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_exports');
    }
};
