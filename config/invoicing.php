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

    /*
    |--------------------------------------------------------------------------
    | Supplier invoice number mask fallback
    |--------------------------------------------------------------------------
    |
    | Used when a user has not configured their own supplier_invoice_number_mask.
    | Kept distinct from the outgoing Proforma prefix (PF) purely for visual
    | clarity — the two live in separate tables with independent sequences.
    |
    */

    'supplier_invoice_number_mask' => env('INVOICING_SUPPLIER_INVOICE_NUMBER_MASK', 'DF-{YYYY}-{NNNN}'),

];
