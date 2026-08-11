<?php

namespace App\Helpers\DataSource\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Резолвит соединение "remote_database" под конкретные креды источника данных.
 *
 * Одной записи в config() недостаточно: DB::connection() отдаёт уже созданное
 * соединение из кэша менеджера, а конфиг читается только в момент первого
 * создания. В долгоживущем процессе (queue:work) это значит, что первый же
 * источник данных "занимает" имя remote_database до перезапуска воркера,
 * и все следующие источники молча работают с чужой базой.
 *
 * Поэтому перед выдачей соединения сбрасываем закешированное (DB::purge),
 * если конфиг отличается от применённого или это первое обращение объекта.
 */
trait ManagesRemoteConnection
{
    private bool $remoteConnectionResolved = false;

    protected function remoteConnection(array $config)
    {
        $applied = config('database.connections.remote_database');

        if (!$this->remoteConnectionResolved || $applied !== $config) {

            config([
                'database.connections.remote_database' => $config,
            ]);

            DB::purge('remote_database');

            $this->remoteConnectionResolved = true;
        }

        return DB::connection('remote_database');
    }
}
