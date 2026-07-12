<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook delivery retention
    |--------------------------------------------------------------------------
    |
    | Delivery attempt logs (webhook_deliveries) older than this many days
    | are purged by qasa:integrations:purge-webhook-deliveries.
    |
    */

    'webhook_delivery_retention_days' => (int) env('QASA_WEBHOOK_DELIVERY_RETENTION_DAYS', 14),

];
