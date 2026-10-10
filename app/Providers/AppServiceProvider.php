<?php

namespace App\Providers;

use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{

    public function register(): void
    {

    }

    public function boot(): void
    {
        // `composer dev` (php artisan dev) поднимает вкладки: server, queue, logs и vite уже в списке
        // по умолчанию; добавляем Reverb (realtime) и планировщик.
        DevCommands::artisan('reverb:start', 'reverb');
        DevCommands::artisan('schedule:work', 'schedule');
    }
}
