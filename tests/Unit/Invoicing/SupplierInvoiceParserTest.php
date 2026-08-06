<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Services\SupplierInvoiceParser;

it('extracts supplier invoice fields from a Slovak document', function (): void {
    $text = <<<'TEXT'
        Faktúra číslo: INV-2026-042
        IČO: 12345678
        DIČ: 1023456789
        Dátum vystavenia: 01.07.2026
        Dátum splatnosti: 15.07.2026
        Variabilný symbol: 2026042
        Celkom k úhrade: 1 234,56 EUR
        IBAN: SK3112000000198742637541
        TEXT;

    $suggestions = (new SupplierInvoiceParser)->parse($text);

    expect($suggestions['supplier_invoice_number'])->toBe('INV-2026-042')
        ->and($suggestions['ico'])->toBe('12345678')
        ->and($suggestions['dic'])->toBe('1023456789')
        ->and($suggestions['issued_at'])->toBe('2026-07-01')
        ->and($suggestions['due_at'])->toBe('2026-07-15')
        ->and($suggestions['variable_symbol'])->toBe('2026042')
        ->and($suggestions['total'])->toBe(1234.56)
        ->and($suggestions['iban'])->toBe('SK3112000000198742637541')
        ->and($suggestions['currency'])->toBe('EUR');
});

it('extracts supplier invoice fields from a Czech document', function (): void {
    $text = <<<'TEXT'
        Faktura c.: 2026/0088
        ICO: 87654321
        Datum vystaveni: 03.06.2026
        Datum splatnosti: 17.06.2026
        Celkem k úhradě: 999,00 CZK
        TEXT;

    $suggestions = (new SupplierInvoiceParser)->parse($text);

    expect($suggestions['ico'])->toBe('87654321')
        ->and($suggestions['issued_at'])->toBe('2026-06-03')
        ->and($suggestions['due_at'])->toBe('2026-06-17')
        ->and($suggestions['total'])->toBe(999.0)
        ->and($suggestions['currency'])->toBe('CZK');
});

it('returns only matched fields for sparse text', function (): void {
    $suggestions = (new SupplierInvoiceParser)->parse('Random unrelated text with nothing to match.');

    expect($suggestions)->toBe([]);
});

/*
 * The capture used to allow only digits and spaces before the separator, so
 * an invoice printing its total with a dot for thousands matched from the
 * "1." onwards and produced 1.23 out of 1.234,56 — the same three orders of
 * magnitude AmountString exists to stop. Both fixtures above print the
 * space-separated form, which is why it stood.
 */
it('reads a total however the supplier groups its thousands', function (string $printed, float $expected): void {
    $suggestions = (new SupplierInvoiceParser)->parse("Celkom k úhrade: {$printed} EUR");

    expect($suggestions['total'])->toBe($expected);
})->with([
    'space thousands' => ['1 234,56', 1234.56],
    'dot thousands' => ['1.234,56', 1234.56],
    'comma thousands' => ['1,234.56', 1234.56],
    'millions' => ['1.234.567,89', 1234567.89],
    'no thousands' => ['999,00', 999.0],
]);
