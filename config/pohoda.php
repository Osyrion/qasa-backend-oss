<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default payment type
    |--------------------------------------------------------------------------
    |
    | Pohoda paymentType id assigned to every exported invoice — there is no
    | per-invoice payment method captured elsewhere in the app. VAT rate
    | thresholds are not duplicated here; they are read from
    | config('taxation.*.vat_rates') to avoid the SK/CZ config drifting out
    | of sync between the two places.
    |
    */

    'default_payment_type' => env('POHODA_DEFAULT_PAYMENT_TYPE', 'draft'),

];
