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

    /*
    | Экспорт дашборда (PDF/Word): страницу дашборда открывает headless Chrome
    | на сервере и снимает её как есть. render_url — адрес, по которому сам сервер
    | достаёт своё приложение (по умолчанию APP_URL), chrome_path — путь к Chrome/Chromium
    | (пусто — искать в PATH).
    */
    'dashboard' => [
        'render_url' => env('EXPORT_RENDER_URL', env('APP_URL', 'http://127.0.0.1')),
        'chrome_path' => env('EXPORT_CHROME_PATH'),
        'node' => env('EXPORT_NODE_BINARY', 'node'),
        // Ширина страницы дашборда при съёмке, px. 1280 — как на экране компьютера: сетка виджетов
        // (например, четыре карточки в ряд) совпадает с тем, что видит человек.
        'width' => (int) env('EXPORT_DASHBOARD_WIDTH', 1280),
        // PDF — страницы A4; альбомные, чтобы такая ширина оставалась читаемой.
        'pdf_landscape' => (bool) env('EXPORT_DASHBOARD_PDF_LANDSCAPE', true),
        'timeout' => (int) env('EXPORT_DASHBOARD_TIMEOUT', 240),
        'ttl_days' => (int) env('EXPORT_DASHBOARD_TTL_DAYS', 7),
    ],

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
