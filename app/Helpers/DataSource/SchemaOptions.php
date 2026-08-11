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
     * поля (default, confidence, match_rate), нужные модели для более точного связывания таблиц.
     */
    public static function detailed(): array
    {
        return [
            'count_rows',
            'columns',
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
