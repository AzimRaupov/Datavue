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
 * в MAX_ROWS. Обёртка же считает/выбирает ровно то, что нужно условию.
 */
class AlertRunner
{
    /**
     * @return array{
     *     row_count: int,
     *     first_row: ?array,
     *     sample: array<int, array>,
     *     sql: string
     * }
     *
     * @throws RuntimeException если запрос некорректен или база отвечает ошибкой
     */
    public function run(Alert $alert, DataSource $dataSource, int $sampleRows): array
    {
        $sql = AlertQueryBuilder::sql($alert, $dataSource);
        $router = new ConnectionProviderRouter($dataSource->id);

        // Образец строк — то же, что уйдёт в письмо и в историю проверки.
        // LIMIT ставится обёрткой, а не самим запросом автора: пользовательский
        // LIMIT (если есть) уже отработал внутри подзапроса.
        $sampleSql = ReadOnlySqlGuard::sanitize(
            'SELECT * FROM ('.$sql.') AS alert_source LIMIT '.max(1, $sampleRows),
            null
        );

        $sample = ReadOnlySqlGuard::normalizeRows($router->query($sampleSql));

        // Точное число строк отдельным запросом: сам образец мог быть
        // обрезан лимитом, и его длина не годится как ответ на условие "rows".
        $countSql = ReadOnlySqlGuard::sanitize(
            'SELECT COUNT(*) AS alert_row_count FROM ('.$sql.') AS alert_source',
            null
        );

        $countRows = ReadOnlySqlGuard::normalizeRows($router->query($countSql));
        $rowCount = (int) ($countRows[0]['alert_row_count'] ?? count($sample));

        return [
            'row_count' => $rowCount,
            'first_row' => $sample[0] ?? null,
            'sample' => $sample,
            'sql' => $sql,
        ];
    }
}
