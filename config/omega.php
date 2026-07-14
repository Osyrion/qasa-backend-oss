<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ⚠️ UNVERIFIED against the current KROS Omega text-import specification
|--------------------------------------------------------------------------
|
| KROS documents the full ~166-column text-import layout in a downloadable
| spreadsheet (referenced as "Import_24_60.xls" in KROS's own support
| articles) that isn't available as fetchable web content. The row-prefix
| convention (R00/R01/R02) and the "invoicing" document category are
| confirmed from KROS's public FAQ pages; the exact column positions, VAT
| code numbering, and delimiter/encoding below are this project's best
| placeholder guess (semicolon-delimited, Windows-1250, matching the
| convention used by comparable SK/CZ accounting flat-file imports) and
| MUST be validated against KROS's actual specification (or a real Omega
| import test) before this export is relied on in production.
|
*/

return [

    'field_delimiter' => ';',

    'encoding' => 'Windows-1250',

    // T01 = "Fakturácia" (invoicing) document category, per KROS's own FAQ.
    'document_type' => env('OMEGA_DOCUMENT_TYPE', 'T01'),

    /*
    | Numeric VAT rate (%) => Omega VAT code. Placeholder mapping (index
    | position in the SK rate ladder) — replace with the real KROS DPH
    | číselník codes once confirmed.
    */
    'vat_codes' => [
        0 => '0',
        5 => '1',
        19 => '2',
        23 => '3',
        12 => '1',
        21 => '3',
    ],

];
