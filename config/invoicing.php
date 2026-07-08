<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reminder cooldown
    |--------------------------------------------------------------------------
    |
    | Minimum number of days between two payment reminders for the same
    | invoice — prevents accidentally spamming a client.
    |
    */

    'reminder_cooldown_days' => env('INVOICING_REMINDER_COOLDOWN_DAYS', 3),

];
