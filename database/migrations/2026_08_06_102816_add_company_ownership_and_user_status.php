<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {

            $table->foreignId('owner_id')
                ->nullable()
                ->after('name')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {

            $table->boolean('is_active')->default(true)->after('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
