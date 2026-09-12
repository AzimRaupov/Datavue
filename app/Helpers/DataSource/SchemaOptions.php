<?php

namespace App\Helpers\DataSource;

/**
 * Общие наборы опций для ConnectionProviderRouter::getSchema(), чтобы не дублировать
 * один и тот же массив в DashboardGenerator/DashboardReGenerator/ReviewWidgetsDashboard.
 */
class SchemaOptions
{
    /**
     * Используется при определении групп/виджетов/инструкций — минимально необходимый набор.
     */
    public static function basic(): array
    {
        return [
            'count_rows',
            'columns',
            'relations' => [
                'column' => [
                    'type',
                    'nullable',
                    'key',
                ],
                'relation' => [
                    'table',
                ],
            ],
        ];
    }

    /**
     * Используется при генерации/проверке контента виджета — включает дополнительные
     * поля (default, confidence, match_rate), нужные модели для более точного связывания таблиц,
     * а также примеры реальных значений текстовых колонок (sample_values) — без них модель
     * не знает, какие строки реально лежат в столбце типа "статус"/"категория", и на фильтрах
     * по значению вынуждена их придумывать (см. WidgetSpecAi/WidgetQueryAi).
     */
    public static function detailed(): array
    {
        return [
            'count_rows',
            'columns',
            'sample_values',
            'relations' => [
                'column' => [
                    'type',
                    'nullable',
                    'key',
                    'default',
                ],
                'relation' => [
                    'column',
                    'table',
                    'confidence',
                    'match_rate',
                ],
            ],
        ];
    }
}
