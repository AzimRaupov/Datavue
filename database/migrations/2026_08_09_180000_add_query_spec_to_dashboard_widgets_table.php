<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->json('query_spec')->nullable()->after('code_path');
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->dropColumn('query_spec');
        });
    }
};
