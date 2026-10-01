<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intent_samples', function (Blueprint $table) {
            $table->id();

            $table->text('text');

            $table->char('text_hash', 64)->unique();

            $table->string('label', 20)->index();

            $table->string('predicted', 20)->nullable();
            $table->float('confidence')->nullable();

            $table->string('source', 20)->default('gpt');

            $table->unsignedBigInteger('chat_id')->nullable()->index();
            $table->unsignedBigInteger('message_id')->nullable()->index();

            $table->boolean('used_in_training')->default(false)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intent_samples');
    }
};
