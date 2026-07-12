<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Slot size
    |--------------------------------------------------------------------------
    |
    | Minimum event length and grid alignment for starts_at/ends_at, in
    | minutes. Used by the alignment/duration validation rules and by the
    | ICS import snap-to-grid normalization.
    |
    */

    'slot_minutes' => 15,

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    |
    | Used only to interpret zoned/UTC DTSTART/DTEND values during ICS
    | import — events themselves are stored as naive local wall-clock
    | datetimes, matching the rest of the module.
    |
    */

    'timezone' => env('CALENDAR_TIMEZONE', env('QASA_SCHEDULE_TIMEZONE', 'Europe/Bratislava')),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Controls how far back qasa:calendar:purge-past keeps events (by
    | ends_at). "current_month" (OSS default) keeps the current month
    | onward; "months_after_end" (SaaS) keeps events for N months after
    | they end. Switching mode is config-only, no code change needed.
    |
    */

    'retention' => [
        'mode' => env('CALENDAR_RETENTION_MODE', 'current_month'),
        'months_after_end' => (int) env('CALENDAR_RETENTION_MONTHS_AFTER_END', 3),
    ],

];
