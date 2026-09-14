<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_source_types', function (Blueprint $table) {
            $table->string('label')->nullable()->after('name');

            $table->enum('kind', ['file', 'database', 'api'])
                ->default('database')
                ->after('label');

            $table->string('icon', 50)->nullable()->after('kind');
            $table->unsignedSmallInteger('default_port')->nullable()->after('icon');
            $table->boolean('is_active')->default(true)->after('default_port');
            $table->unsignedSmallInteger('position')->default(0)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('data_source_types', function (Blueprint $table) {
            $table->dropColumn([
                'label',
                'kind',
                'icon',
                'default_port',
                'is_active',
                'position',
            ]);
        });
    }
};
