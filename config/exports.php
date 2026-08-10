<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Выгрузка результатов запроса в файл
    |--------------------------------------------------------------------------
    |
    | Пользователь просит в чате «посчитай топ-10 клиентов и сохрани в csv»,
    | платформа генерирует Python-код, выполняет его на источнике данных
    | и отдаёт готовый файл ссылкой. Здесь — ограничения этого механизма.
    |
    */

    /**
     * Потолок строк в файле. Экспорт идёт через pandas: весь результат
     * оказывается в памяти воркера, поэтому «выгрузи всю таблицу» на базе
     * в десятки миллионов строк должно упереться в лимит, а не в OOM.
     */
    'max_rows' => (int) env('EXPORT_MAX_ROWS', 100000),

    /**
     * Для «документных» форматов потолок ниже: PDF на 100 000 строк
     * собирается минутами и всё равно нечитаем.
     */
    'pdf_max_rows' => (int) env('EXPORT_PDF_MAX_ROWS', 2000),
    'docx_max_rows' => (int) env('EXPORT_DOCX_MAX_ROWS', 5000),

    /** Сколько времени даём Python-скрипту, сек. */
    'timeout' => (int) env('EXPORT_TIMEOUT', 180),

    /** Сколько попыток даём модели на исправление собственного кода. */
    'max_attempts' => (int) env('EXPORT_MAX_ATTEMPTS', 3),

    /**
     * Срок жизни ссылки на файл. Ссылка публичная (знающий её скачает файл),
     * поэтому она не должна работать вечно. null — не протухает.
     */
    'ttl_days' => (int) env('EXPORT_TTL_DAYS', 30),

    /**
     * Разделитель CSV. Точка с запятой — потому что файлы открывают в Excel
     * с русской локалью, где запятая является десятичным разделителем
     * и таблица «схлопывается» в одну колонку.
     */
    'csv_delimiter' => env('EXPORT_CSV_DELIMITER', ';'),

    /** Сколько строк показать в чате как предпросмотр содержимого файла. */
    'preview_rows' => (int) env('EXPORT_PREVIEW_ROWS', 10),

    /**
     * Шрифты для PDF. У встроенных шрифтов reportlab нет кириллицы —
     * без TTF русский текст в файле превращается в чёрные квадраты.
     */
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
