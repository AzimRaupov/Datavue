<?php

return [

    'min_interval_minutes' => (int) env('ALERT_MIN_INTERVAL', 15),

    'intervals' => [15, 30, 60, 180, 360, 720, 1440],

    'default_interval_minutes' => 60,

    'sql_timeout' => (int) env('ALERT_SQL_TIMEOUT', 60),
    'python_timeout' => (int) env('ALERT_PYTHON_TIMEOUT', 60),

    'sample_rows' => (int) env('ALERT_SAMPLE_ROWS', 20),

    'csv_max_rows' => (int) env('ALERT_CSV_MAX_ROWS', 5000),

    'repeat_after_minutes' => (int) env('ALERT_REPEAT_AFTER', 1440),

    'error_cooldown_hours' => (int) env('ALERT_ERROR_COOLDOWN', 24),

    'disable_after_failures' => (int) env('ALERT_DISABLE_AFTER_FAILURES', 10),

    'history_ttl_days' => (int) env('ALERT_HISTORY_TTL_DAYS', 90),

    'max_per_company' => (int) env('ALERT_MAX_PER_COMPANY', 100),

    'max_recipients' => (int) env('ALERT_MAX_RECIPIENTS', 20),

    'dispatch_batch' => (int) env('ALERT_DISPATCH_BATCH', 200),

];
