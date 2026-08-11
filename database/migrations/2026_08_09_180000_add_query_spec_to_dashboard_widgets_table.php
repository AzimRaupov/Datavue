<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Спецификация запросов виджета вместо сгенерированной Python-программы.
 *
 * Раньше содержимое виджета было файлом generated_script.py: модель писала
 * программу, которая подключалась к базе, выполняла SQL и вручную собирала
 * вложенный JSON под схему виджета. Разбор 87 таких скриптов показал, что
 * ни один из них не использовал pandas или numpy — весь Python был
 * транспортировкой строк, а считал всегда SQL.
 *
 * Теперь модель отдаёт спецификацию:
 *   {
 *     "queries": { "main": "SELECT ... AS label, ... AS value FROM ..." },
 *     "shape": "series_values",
 *     "presentation": { ... }   // необязательно: оформление, которого нет в данных
 *   }
 *
 * Вложенную структуру собирает WidgetShapeMapper — один раз и под тестами,
 * а не заново моделью на каждый виджет.
 *
 * code_path остаётся: старые виджеты продолжают работать на Python, пока их
 * не перегенерируют. WidgetRunController выбирает путь по наличию query_spec.
 */
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
