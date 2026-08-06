<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Владелец компании — тот, кто её зарегистрировал. Нужен, чтобы
            // другие администраторы компании не могли его удалить или разжаловать
            // и оставить компанию без единого администратора.
            $table->foreignId('owner_id')
                ->nullable()
                ->after('name')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            // Позволяет отключить сотрудника, не удаляя его вместе с историей чатов.
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
