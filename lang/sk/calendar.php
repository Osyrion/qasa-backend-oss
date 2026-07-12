<?php

declare(strict_types=1);

return [
    'validation' => [
        'not_aligned' => 'Časy musia byť zarovnané na :minutes-minútové bloky.',
        'min_duration' => 'Udalosť musí trvať aspoň :minutes minút.',
        'ends_before_starts' => 'Udalosť musí skončiť po jej začiatku.',
        'must_end_same_day' => 'Udalosť musí skončiť najneskôr o polnoci dňa, v ktorom začala.',
    ],

    'import' => [
        'unsupported_format' => 'Nepodarilo sa určiť formát súboru.',
        'recurring_not_supported' => 'Opakujúce sa udalosti nie sú podporované a boli preskočené.',
        'multi_day_not_supported' => 'Viacdňové udalosti nie sú podporované a boli preskočené.',
        'crosses_midnight' => 'Udalosť presahuje cez polnoc a bola preskočená.',
    ],
];
