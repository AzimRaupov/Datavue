<?php

return [

    // Сколько отчётов по расписанию может быть у одной компании.
    'max_per_company' => (int) env('REPORT_MAX_PER_COMPANY', 50),

    'max_recipients' => (int) env('REPORT_MAX_RECIPIENTS', 20),

    // Файл тяжелее этого не прикладывается к письму, вместо него — ссылка на скачивание.
    'max_attachment_mb' => (int) env('REPORT_MAX_ATTACHMENT_MB', 15),

    // Если отправка не удалась (почта или Chrome недоступны), отчёт не ждёт следующего срабатывания
    // (для недельного — неделю), а повторяется через эти паузы, в минутах.
    'retry_delays_minutes' => [5, 15, 60, 180],

    // Сколько срабатываний подряд отчёт может не уйти (со всеми повторами), прежде чем он выключится сам.
    'disable_after_failures' => (int) env('REPORT_DISABLE_AFTER_FAILURES', 3),

    // Запись «отправляется» старше этого (в минутах) считается зависшей: задачу убили или сервер перезапустили.
    'stale_run_minutes' => (int) env('REPORT_STALE_RUN_MINUTES', 120),

    // Сколько дней хранить историю отправок.
    'history_ttl_days' => (int) env('REPORT_HISTORY_TTL_DAYS', 180),

    'dispatch_batch' => (int) env('REPORT_DISPATCH_BATCH', 100),

    // Часовой пояс по умолчанию, если у отчёта он не задан.
    'default_timezone' => env('REPORT_DEFAULT_TIMEZONE', 'UTC'),

    // Ссылка на скачивание в письме живёт столько дней (когда файл не вложен).
    'link_ttl_days' => (int) env('REPORT_LINK_TTL_DAYS', 7),

];
