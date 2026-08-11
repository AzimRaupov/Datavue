<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Расписание
|--------------------------------------------------------------------------
|
| Требует запущенного планировщика:
|   * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
|
*/

// Дообучение классификатора намерений на фразах, размеченных языковой моделью.
// Раз в неделю и ночью: обучение занимает секунды, но занимает процессор,
// а команда сама пропустит запуск, если новых примеров меньше порога.
Schedule::command('intents:retrain')
    ->weeklyOn(1, '03:00')
    ->withoutOverlapping()
    ->runInBackground();

// Удаление протухших выгрузок вместе с файлами: ссылки перестают работать
// по истечении exports.ttl_days, а файлы иначе копятся вечно.
Schedule::command('exports:prune')
    ->dailyAt('04:00')
    ->withoutOverlapping();
