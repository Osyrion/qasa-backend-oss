<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Edition
    |--------------------------------------------------------------------------
    |
    | "oss"  — self-hosted single-user core: every core ability is granted
    |          via Gate::before and data isolation stays with HasUserScope.
    | "saas" — spatie roles/permissions, teams, billing and the admin panel
    |          take over authorization.
    |
    */

    'edition' => env('QASA_EDITION', 'oss'),

    'features' => [

        // Public registration endpoint; the OSS edition creates users via
        // the `qasa:user` artisan command instead.
        'registration' => (bool) env('QASA_REGISTRATION', false),

    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule timezone
    |--------------------------------------------------------------------------
    |
    | Timezone for scheduled jobs (recurring invoice generation). Explicit so
    | that deploying to a UTC host doesn't silently shift the run times the
    | SK/CZ users expect.
    |
    */

    'schedule_timezone' => env('QASA_SCHEDULE_TIMEZONE', 'Europe/Bratislava'),

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | Locales the API translates user-facing messages into. Resolved per
    | request by App\Modules\Shared\Presentation\Middleware\SetLocale from
    | the authenticated user's `locale` column, falling back to the
    | Accept-Language header and then config('app.locale').
    |
    */

    'locales' => [
        'available' => ['en', 'sk'],
    ],

    /*
    |--------------------------------------------------------------------------
    | VIES grace window
    |--------------------------------------------------------------------------
    |
    | Days a client's last successful VIES check (clients.vat_verified_at)
    | remains trusted when VIES itself is unreachable at issuance time. Only
    | covers a down VIES service — a number VIES actively rejects always
    | blocks issuance, grace window or not.
    |
    */

    'vies_grace_days' => (int) env('QASA_VIES_GRACE_DAYS', 30),

];
