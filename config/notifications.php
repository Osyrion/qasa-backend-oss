<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | In-app notification retention
    |--------------------------------------------------------------------------
    |
    | qasa:notifications:purge deletes *read* notifications older than this
    | many days. Unread ones are never purged on age alone — an unread
    | notification is an outstanding message to the user, and silently
    | removing it is worse than a long list.
    |
    */

    'retention_days' => (int) env('QASA_NOTIFICATION_RETENTION_DAYS', 90),

];
