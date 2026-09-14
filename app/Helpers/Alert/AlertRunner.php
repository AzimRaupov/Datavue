<?php

namespace App\Helpers\Alert;

use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\DataSource\ReadOnlySqlGuard;
use App\Models\Alert;
use App\Models\DataSource;
use RuntimeException;

class AlertRunner
{

    public function run(Alert $alert, DataSource $dataSource, int $sampleRows, int $exportRows = 0): array
    {
        $sql = AlertQueryBuilder::sql($alert, $dataSource);
        $router = new ConnectionProviderRouter($dataSource->id);

        $fetchLimit = max(1, $sampleRows, $exportRows);

        $fetchSql = ReadOnlySqlGuard::sanitize(
            'SELECT * FROM ('.$sql.') AS alert_source LIMIT '.$fetchLimit,
            null
        );

        $fetched = ReadOnlySqlGuard::normalizeRows($router->query($fetchSql));

        $countSql = ReadOnlySqlGuard::sanitize(
            'SELECT COUNT(*) AS alert_row_count FROM ('.$sql.') AS alert_source',
            null
        );

        $countRows = ReadOnlySqlGuard::normalizeRows($router->query($countSql));
        $rowCount = (int) ($countRows[0]['alert_row_count'] ?? count($fetched));

        return [
            'row_count' => $rowCount,
            'first_row' => $fetched[0] ?? null,
            'sample' => array_slice($fetched, 0, $sampleRows),
            'export_rows' => $exportRows > 0 ? array_slice($fetched, 0, $exportRows) : [],
            'sql' => $sql,
        ];
    }
}
