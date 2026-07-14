<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Activity log retention
    |--------------------------------------------------------------------------
    |
    | Entries (activity_log) older than this many days are purged by
    | qasa:activity:purge. Defaults to 2 years — the audit trail is a
    | compliance record, not a rolling debug log.
    |
    */

    'retention_days' => (int) env('QASA_ACTIVITY_LOG_RETENTION_DAYS', 730),

];
