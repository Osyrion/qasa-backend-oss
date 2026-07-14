<?php

return [
    'SK' => [
        'vat_rates' => [0, 5, 19, 23],
        'default_vat_rate' => 23,
        'currency' => 'EUR',
        'ico_label' => 'IČO',
        'dic_label' => 'DIČ',
        'vat_id_label' => 'IČ DPH',
        'vat_id_prefix' => 'SK',
        'invoice_label' => 'Faktúra',
        'vs_label' => 'Variabilný symbol',
    ],
    'CZ' => [
        'vat_rates' => [0, 12, 21],
        'default_vat_rate' => 21,
        'currency' => 'CZK',
        'ico_label' => 'IČO',
        'dic_label' => 'DIČ',
        'vat_id_label' => 'DIČ',
        'vat_id_prefix' => 'CZ',
        'invoice_label' => 'Faktura',
        'vs_label' => 'Variabilní symbol',
    ],
];
