<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->longText('code')->nullable()->after('code_path');

            $table->longText('code_previous')->nullable()->after('code');

            $table->string('content_mode', 16)->default('python')->after('code_previous');

            $table->string('origin', 16)->default('ai')->after('content_mode');

            $table->text('last_error')->nullable()->after('origin');
            $table->timestamp('last_run_at')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->dropColumn([
                'code',
                'code_previous',
                'content_mode',
                'origin',
                'last_error',
                'last_run_at',
            ]);
        });
    }
};
