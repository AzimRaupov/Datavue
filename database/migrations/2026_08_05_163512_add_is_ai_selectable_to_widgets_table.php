<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('widgets', function (Blueprint $table) {

            $table->boolean('is_ai_selectable')->default(true)->after('scheme_description');
        });
    }

    public function down(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            $table->dropColumn('is_ai_selectable');
        });
    }
};
