<?php

declare(strict_types=1);

return [
    'rate_not_deletable' => 'Platnú alebo historickú sadzbu nie je možné zmazať — vytvorte novú sadzbu s neskorším dátumom platnosti.',
    'global_rate_cannot_have_scope' => 'Globálna sadzba nemôže byť viazaná na klienta ani zákazku.',
    'client_rate_requires_client_id' => 'Sadzba klienta vyžaduje client_id a nesmie mať order_id.',
    'client_not_found' => 'Klient neexistuje alebo nepatrí prihlásenému užívateľovi.',
    'order_rate_requires_order_id' => 'Sadzba zákazky vyžaduje order_id a nesmie mať client_id.',
    'order_not_found' => 'Zákazka neexistuje alebo nepatrí prihlásenému užívateľovi.',
    'personal_order_cannot_have_rate' => 'Osobná zákazka (bez klienta) nemôže mať nastavenú sadzbu.',
    'currency_mismatch' => 'Mena sadzby (:rate_currency) sa nezhoduje s menou rozsahu (:scope_currency).',
];
