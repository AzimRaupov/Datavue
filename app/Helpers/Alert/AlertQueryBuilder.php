<?php

namespace App\Helpers\Alert;

use App\Helpers\DataSource\ReadOnlySqlGuard;
use App\Helpers\Widget\WidgetQueryComposer;
use App\Models\Alert;
use App\Models\DataSource;
use RuntimeException;

class AlertQueryBuilder
{

    private const FAMILY = 'table';

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

        return ReadOnlySqlGuard::sanitize($query, null);
    }
}
