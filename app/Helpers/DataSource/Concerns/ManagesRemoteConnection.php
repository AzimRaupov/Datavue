<?php

namespace App\Helpers\DataSource\Concerns;

use Illuminate\Support\Facades\DB;

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
