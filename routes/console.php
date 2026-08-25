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
//
// Only core commands are registered here. Premium modules register their
// own Schedule entries from their service provider's boot() method — see
// AutomationServiceProvider, SubscriptionsServiceProvider,
// CalendarServiceProvider, IntegrationsServiceProvider — so an OSS build
// (which deletes those modules) never ends up with a cron entry pointing
// at a command that doesn't exist.
Schedule::command('qasa:invoices:scan-inbox')
    ->everyFifteenMinutes()
    ->timezone((string) config('qasa.schedule_timezone'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('qasa:invoices:overdue-digest')
    ->dailyAt('08:00')
    ->timezone((string) config('qasa.schedule_timezone'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('qasa:activity:purge')
    ->dailyAt('04:15')
    ->timezone((string) config('qasa.schedule_timezone'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('qasa:notifications:purge')
    ->dailyAt('04:45')
    ->timezone((string) config('qasa.schedule_timezone'))
    ->withoutOverlapping()
    ->onOneServer();

// Every minute, and deliberately without ->onOneServer(): the file it writes
// describes the machine it runs on, so on a second app server the second file
// is the point, not a duplicate. Its own timestamp is what the "scheduler is
// dead" alert watches — see ExportOperationalMetricsCommand.
Schedule::command('qasa:metrics:export')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('qasa:idempotency-keys:purge')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Ahead of the retention purges above, so an account that becomes eligible
// today is anonymised before the log entry recording it would itself age out.
Schedule::command('qasa:accounts:purge')
    ->dailyAt('03:30')
    ->timezone((string) config('qasa.schedule_timezone'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('qasa:privacy:purge-request-metadata')
    ->dailyAt('04:00')
    ->timezone((string) config('qasa.schedule_timezone'))
    ->withoutOverlapping()
    ->onOneServer();
