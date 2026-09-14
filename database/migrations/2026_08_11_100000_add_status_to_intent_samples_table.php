<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intent_samples', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->index()->after('source');
            $table->string('reject_reason')->nullable()->after('status');
        });

        \Illuminate\Support\Facades\DB::table('intent_samples')->update(['status' => 'confirmed']);
    }

    public function down(): void
    {
        Schema::table('intent_samples', function (Blueprint $table) {
            $table->dropColumn(['status', 'reject_reason']);
        });
    }
};
