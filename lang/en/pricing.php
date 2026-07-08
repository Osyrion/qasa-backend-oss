<?php

declare(strict_types=1);

return [
    'rate_not_deletable' => 'A current or historical rate cannot be deleted — create a new rate with a later effective date instead.',
    'global_rate_cannot_have_scope' => 'A global rate cannot be tied to a client or an order.',
    'client_rate_requires_client_id' => 'A client rate requires client_id and must not have order_id.',
    'client_not_found' => 'The client does not exist or does not belong to the logged-in user.',
    'order_rate_requires_order_id' => 'An order rate requires order_id and must not have client_id.',
    'order_not_found' => 'The order does not exist or does not belong to the logged-in user.',
    'personal_order_cannot_have_rate' => 'A personal order (without a client) cannot have a rate set.',
    'currency_mismatch' => 'The rate currency (:rate_currency) does not match the scope currency (:scope_currency).',
];
