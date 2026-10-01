<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table) {
            $table->string('offer_type', 20)->nullable()->after('answer');
            $table->text('offer_summary')->nullable()->after('offer_type');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table) {
            $table->dropColumn(['offer_type', 'offer_summary']);
        });
    }
};
