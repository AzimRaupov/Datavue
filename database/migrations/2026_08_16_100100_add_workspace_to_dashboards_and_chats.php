<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Дашборды и разговоры переезжают в рабочие пространства.
 *
 * Данные не теряются: у каждого существующего чата уже была своя работа — он
 * и его дашборды становятся отдельным пространством. Дашборды, собранные руками
 * (чата у них нет), собираются в пространство своего источника.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboards', function (Blueprint $table) {
            $table->foreignId('workspace_id')
                ->nullable()
                ->after('company_id')
                ->constrained('workspaces')
                ->nullOnDelete();
        });

        Schema::table('ai_chats', function (Blueprint $table) {
            $table->foreignId('workspace_id')
                ->nullable()
                ->after('company_id')
                ->constrained('workspaces')
                ->nullOnDelete();
        });

        $this->moveChats();
        $this->moveOrphanDashboards();
    }

    public function down(): void
    {
        Schema::table('dashboards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workspace_id');
        });

        Schema::table('ai_chats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workspace_id');
        });
    }

    /**
     * Каждый чат — это уже сложившаяся работа: свой источник, своя переписка
     * и свои дашборды. Поэтому он и становится пространством, а не сваливается
     * вместе с остальными в одну кучу по источнику.
     */
    private function moveChats(): void
    {
        foreach (DB::table('ai_chats')->orderBy('id')->get() as $chat) {
            // Источник у старых чатов мог остаться на самой базе
            // (data_sources.chat_id) — см. AiChat::resolveDataSource().
            $sourceId = $chat->data_source_id
                ?? DB::table('data_sources')->where('chat_id', $chat->id)->value('id');

            $workspaceId = DB::table('workspaces')->insertGetId([
                'company_id' => $chat->company_id,
                'created_by' => $chat->user_id,
                'data_source_id' => $sourceId,
                'name' => trim((string) $chat->title) !== '' ? $chat->title : 'Рабочее пространство',
                'created_at' => $chat->created_at ?? now(),
                'updated_at' => now(),
            ]);

            DB::table('ai_chats')->where('id', $chat->id)->update(['workspace_id' => $workspaceId]);
            DB::table('dashboards')->where('chat_id', $chat->id)->update(['workspace_id' => $workspaceId]);
        }
    }

    /**
     * Дашборды без чата — собранные руками. Их группируем по источнику:
     * ничего другого о том, что человек считал одной задачей, у нас нет.
     */
    private function moveOrphanDashboards(): void
    {
        $orphans = DB::table('dashboards')
            ->whereNull('workspace_id')
            ->orderBy('id')
            ->get();

        $byKey = [];

        foreach ($orphans as $dashboard) {
            $key = $dashboard->company_id.':'.($dashboard->data_source_id ?? 0);

            if (!isset($byKey[$key])) {
                $sourceName = $dashboard->data_source_id
                    ? DB::table('data_sources')->where('id', $dashboard->data_source_id)->value('name')
                    : null;

                $byKey[$key] = DB::table('workspaces')->insertGetId([
                    'company_id' => $dashboard->company_id,
                    'created_by' => $dashboard->created_by,
                    'data_source_id' => $dashboard->data_source_id,
                    'name' => $sourceName ? 'Дашборды — '.$sourceName : 'Мои дашборды',
                    'created_at' => $dashboard->created_at ?? now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('dashboards')
                ->where('id', $dashboard->id)
                ->update(['workspace_id' => $byKey[$key]]);
        }
    }
};
