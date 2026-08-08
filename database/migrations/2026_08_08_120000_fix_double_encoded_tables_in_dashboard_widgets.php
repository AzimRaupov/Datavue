<?php

use App\Models\DashboardWidget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Чинит поле tables у виджетов, созданных через обновление дашборда.
     *
     * DashboardReGenerator сам вызывал json_encode перед записью, хотя модель
     * кодирует значение кастом. Результат — JSON-строка вместо JSON-массива,
     * а при повторных обновлениях кодирование накладывалось ещё раз. Чтение
     * такого поля возвращало строку, и getSchema() падал с TypeError.
     *
     * Здесь разворачиваем накопившиеся слои и записываем нормальный массив.
     */
    public function up(): void
    {
        $fixed = 0;

        DB::table('dashboard_widgets')
            ->select('id', 'tables')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$fixed) {
                foreach ($rows as $row) {
                    if ($row->tables === null) {
                        continue;
                    }

                    // Корректная запись — это JSON-массив. Всё остальное чиним.
                    $decoded = json_decode($row->tables, true);

                    if (is_array($decoded)) {
                        continue;
                    }

                    $normalized = DashboardWidget::normalizeTables($row->tables);

                    DB::table('dashboard_widgets')
                        ->where('id', $row->id)
                        ->update([
                            'tables' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
                        ]);

                    $fixed++;
                }
            });

        Log::info('Migration: dashboard_widgets.tables normalized', ['fixed' => $fixed]);
    }

    /**
     * Откат не предусмотрен: возвращать данные в заведомо сломанный вид незачем.
     */
    public function down(): void
    {
        //
    }
};
