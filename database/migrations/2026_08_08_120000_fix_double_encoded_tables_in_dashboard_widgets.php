<?php

use App\Models\DashboardWidget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{

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

    public function down(): void
    {

    }
};
