<?php

namespace App\Helpers\Alert;

use App\Helpers\DataSource\ReadOnlySqlGuard;
use App\Helpers\Widget\WidgetQueryComposer;
use App\Models\Alert;
use App\Models\DataSource;
use RuntimeException;

/**
 * Достаёт SQL алерта из его настроек, независимо от того, как оно задано.
 *
 * Три режима условия:
 *   - builder: конструктор метрик. Семейство 'table' даёт форму 'rows' —
 *     обычный SELECT без раскладки по слотам виджета (WidgetQueryComposer::
 *     composeRows()), ровно то, что нужно алерту.
 *   - sql: запрос человека, проходит тот же ReadOnlySqlGuard, что и у
 *     чат-агента и у виджетов.
 *   - python: SQL алерту не нужен — сам код решает, сработало ли условие.
 */
class AlertQueryBuilder
{
    /** Семейство виджета, под которым конструктор собирает голый SELECT. */
    private const FAMILY = 'table';

    /**
     * @return string SQL без завершающего ";"
     *
     * @throws RuntimeException если условие некорректно
     */
    public static function sql(Alert $alert, DataSource $dataSource): string
    {
        return match ($alert->mode) {
            Alert::MODE_BUILDER => self::composeBuilder($alert->builder ?? [], $dataSource)['sql'],
            Alert::MODE_SQL => self::fromRawSql($alert),
            default => throw new RuntimeException(
                'У режима python нет SQL — условие решает сам код.'
            ),
        };
    }

    /**
     * Собирает SQL конструктора вместе с итоговыми подписями колонок.
     *
     * Подписи нужны отдельно от текста SQL: конструктор не требует подпись
     * метрики обязательной, и то, что реально станет именем колонки в
     * результате, — либо она, либо дефолт самого composer'а ("Сумма amount"
     * и т.п.). Условию «по значению» нужно опираться на это же имя, а не
     * гадать его заново — расхождение и было причиной ошибки «нет колонки».
     *
     * @return array{sql: string, columns: array{dimensions: array<int, string>, metrics: array<int, string>}}
     *
     * @throws RuntimeException если настройки конструктора некорректны
     */
    public static function composeBuilder(array $builder, DataSource $dataSource): array
    {
        if ($builder === []) {
            throw new RuntimeException('Не заданы настройки конструктора.');
        }

        $composer = new WidgetQueryComposer($dataSource);
        $result = $composer->compose($builder, self::FAMILY);

        if (!$result['ok']) {
            throw new RuntimeException(implode(' ', $result['errors']) ?: 'Не удалось собрать запрос.');
        }

        return ['sql' => $result['sql'], 'columns' => $result['columns']];
    }

    /**
     * Приводит условие «по значению» к настоящему имени колонки результата.
     *
     * Для режима builder колонку не задаёт автор — он выбирает МЕТРИКУ
     * (её порядковый номер, condition.metric_index), а имя колонки в SQL
     * решает сам composer. Для sql колонку называет сам автор в своём
     * запросе — она уже правильная и не трогается.
     *
     * @throws RuntimeException если метрика для условия не выбрана или не существует
     */
    public static function resolveConditionColumn(array $condition, Alert $alert, DataSource $dataSource): array
    {
        if (($condition['kind'] ?? null) !== 'value' || $alert->mode !== Alert::MODE_BUILDER) {
            return $condition;
        }

        $index = $condition['metric_index'] ?? null;
        $isValidIndex = is_int($index) || (is_string($index) && ctype_digit($index));

        if ($index === null || !$isValidIndex) {
            throw new RuntimeException('Выберите метрику, значение которой сравнивает условие.');
        }

        $columns = self::composeBuilder($alert->builder ?? [], $dataSource)['columns'];
        $label = $columns['metrics'][(int) $index] ?? null;

        if ($label === null) {
            throw new RuntimeException('Выбранной метрики больше нет среди настроек конструктора.');
        }

        $condition['column'] = $label;

        return $condition;
    }

    private static function fromRawSql(Alert $alert): string
    {
        $query = trim((string) $alert->query);

        if ($query === '') {
            throw new RuntimeException('Не задан SQL-запрос.');
        }

        // maxRows=null: обёртка, которая реально пойдёт в базу (AlertRunner),
        // сама ставит COUNT(*)/LIMIT — лишний LIMIT здесь только мешал бы.
        return ReadOnlySqlGuard::sanitize($query, null);
    }
}
