<?php

return [

    'max_rows' => (int) env('EXPORT_MAX_ROWS', 100000),

    'pdf_max_rows' => (int) env('EXPORT_PDF_MAX_ROWS', 2000),
    'docx_max_rows' => (int) env('EXPORT_DOCX_MAX_ROWS', 5000),

    'timeout' => (int) env('EXPORT_TIMEOUT', 180),

    'max_attempts' => (int) env('EXPORT_MAX_ATTEMPTS', 3),

    'ttl_days' => (int) env('EXPORT_TTL_DAYS', 30),

    'csv_delimiter' => env('EXPORT_CSV_DELIMITER', ';'),

    'preview_rows' => (int) env('EXPORT_PREVIEW_ROWS', 10),

    'pdf_fonts' => [
        'regular' => [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            '/Library/Fonts/Arial Unicode.ttf',
        ],
        'bold' => [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        ],
    ],

];
