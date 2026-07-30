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

    /*
    |--------------------------------------------------------------------------
    | Edition-dependent validation rules
    |--------------------------------------------------------------------------
    |
    | price_list_items is a Pricing (premium) table, so an exists: rule against
    | it cannot ship in the OSS core — it would fail on a missing table rather
    | than reject a bad value. PricingServiceProvider tightens this.
    |
    */

    'rules' => [
        'price_list_item_id' => ['nullable', 'uuid'],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP tools contributed by premium modules
    |--------------------------------------------------------------------------
    |
    | QasaServer always exposes the core tools; a tool that needs a premium
    | module is appended here by that module's service provider, so deleting
    | the module for the OSS build takes its tool with it.
    |
    */

    'mcp' => [
        'tools' => [],
    ],

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
        'available' => ['en', 'sk', 'cs'],
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
