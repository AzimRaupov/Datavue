<?php

namespace App\Helpers\Alert;

use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\DataSource\ReadOnlySqlGuard;
use App\Models\Alert;
use App\Models\DataSource;
use RuntimeException;

/**
 * Выполняет SQL-условие алерта (mode builder|sql) над источником данных.
 *
 * Запрос автора всегда обёрнут: алерт отвечает на вопрос «сработало или
 * нет», а не «покажи все строки», и тянуть в память результат в миллион
 * строк ради подсчёта — то же самое, из-за чего WidgetQueryRunner упирается
 * в MAX_ROWS. Обёртка же выбирает ровно то, что нужно условию и файлу
 * проверки, одним запросом на бо́льший из двух пределов.
 */
class AlertRunner
{
    /**
     * @param int $exportRows Сколько строк нужно для CSV этой проверки.
     *                        0 — файл не нужен (черновая проверка формы).
     *
     * @return array{
     *     row_count: int,
     *     first_row: ?array,
     *     sample: array<int, array>,
     *     export_rows: array<int, array>,
     *     sql: string
     * }
     *
     * @throws RuntimeException если запрос некорректен или база отвечает ошибкой
     */
    public function run(Alert $alert, DataSource $dataSource, int $sampleRows, int $exportRows = 0): array
    {
        $sql = AlertQueryBuilder::sql($alert, $dataSource);
        $router = new ConnectionProviderRouter($dataSource->id);

        // Одна выборка на оба предела: образец в письмо/историю — это просто
        // начало того же набора, который целиком (в пределах exportRows)
        // ляжет в CSV. Второй запрос ради файла был бы лишним походом в базу.
        $fetchLimit = max(1, $sampleRows, $exportRows);

        $fetchSql = ReadOnlySqlGuard::sanitize(
            'SELECT * FROM ('.$sql.') AS alert_source LIMIT '.$fetchLimit,
            null
        );

        $fetched = ReadOnlySqlGuard::normalizeRows($router->query($fetchSql));

        // Точное число строк отдельным запросом: выборка выше могла быть
        // обрезана лимитом, и её длина не годится как ответ на условие "rows".
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
