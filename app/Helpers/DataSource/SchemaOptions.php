<?php

namespace App\Helpers\DataSource;

class SchemaOptions
{

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
