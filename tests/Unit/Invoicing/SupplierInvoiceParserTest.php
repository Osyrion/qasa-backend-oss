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

it('extracts a domestic account number alongside the variable symbol', function (): void {
    $text = <<<'TEXT'
        Faktura c.: 2026/0090
        Číslo účtu: 19-2000145399/0800
        Variabilní symbol: 20260090
        TEXT;

    $suggestions = (new SupplierInvoiceParser)->parse($text);

    expect($suggestions['account_number'])->toBe('19-2000145399')
        ->and($suggestions['bank_code'])->toBe('0800')
        ->and($suggestions['variable_symbol'])->toBe('20260090');
});

it('only suggests checksum-valid IBANs', function (): void {
    $valid = (new SupplierInvoiceParser)->parse('IBAN: CZ6508000000192000145399');
    $invalid = (new SupplierInvoiceParser)->parse('IBAN: CZ0008000000192000145399');

    expect($valid['iban'])->toBe('CZ6508000000192000145399')
        ->and($invalid)->not->toHaveKey('iban');
});
