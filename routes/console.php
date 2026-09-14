<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('intents:retrain')
    ->weeklyOn(1, '03:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('exports:prune')
    ->dailyAt('04:00')
    ->withoutOverlapping();

Schedule::command('alerts:dispatch')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('alerts:prune')
    ->dailyAt('04:30')
    ->withoutOverlapping();
