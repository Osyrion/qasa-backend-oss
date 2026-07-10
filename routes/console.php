<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Deployment must run `php artisan schedule:run` every minute (cron)
// or keep `php artisan schedule:work` alive.
Schedule::command('qasa:invoices:generate-recurring')
    ->dailyAt('05:00')
    ->timezone((string) config('qasa.schedule_timezone'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('qasa:invoices:scan-inbox')
    ->everyFifteenMinutes()
    ->timezone((string) config('qasa.schedule_timezone'))
    ->withoutOverlapping()
    ->onOneServer();
