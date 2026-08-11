<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Реплика агента, предшествовавшая сообщению пользователя.
 *
 * Классификатор научился смотреть на контекст, и теперь пример без него
 * неполон: «давай» без предыдущего хода неразрешимо в принципе. Хеш
 * уникальности тоже считается по паре — одна реплика при разных предложениях
 * агента это разные обучающие примеры, в них весь смысл.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intent_samples', function (Blueprint $table) {
            $table->text('context')->nullable()->after('text');
        });
    }

    public function down(): void
    {
        Schema::table('intent_samples', function (Blueprint $table) {
            $table->dropColumn('context');
        });
    }
};
