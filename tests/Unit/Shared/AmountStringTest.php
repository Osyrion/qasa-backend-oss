<?php

declare(strict_types=1);

use App\Modules\Shared\Support\AmountString;

/**
 * Four parsers had grown the same one-liner — strip spaces, comma to dot,
 * cast — which reads "1.234,56" and "1,234.56" as 1.234. The first is the
 * Czech/Slovak convention, the second is what Revolut, Wise, Stripe and any
 * en-locale export produce, so both arrive routinely.
 *
 * The contract is pinned here once; the parsers are checked against their own
 * inputs in GenericCsvStatementParserTest, FioStatementParserTest,
 * CsvImportTest and SupplierInvoiceParserTest.
 */
it('reads an amount in any thousands/decimal convention', function (string $written, float $expected): void {
    expect(AmountString::parse($written))->toBe($expected);
})->with([
    'plain integer' => ['1234', 1234.0],
    'dot decimal' => ['1234.56', 1234.56],
    'comma decimal' => ['1234,56', 1234.56],
    'space thousands, comma decimal' => ['1 234,56', 1234.56],
    'space thousands, dot decimal' => ['1 234.56', 1234.56],
    'nbsp thousands' => ["1\u{00A0}234,56", 1234.56],
    'narrow nbsp thousands' => ["1\u{202F}234,56", 1234.56],
    'dot thousands, comma decimal' => ['1.234,56', 1234.56],
    'comma thousands, dot decimal' => ['1,234.56', 1234.56],
    'millions, dot thousands' => ['1.234.567,89', 1234567.89],
    'millions, comma thousands' => ['1,234,567.89', 1234567.89],
    'lone dot with three digits is grouping' => ['1.234', 1234.0],
    'lone comma with three digits is grouping' => ['1,234', 1234.0],
    'lone separator with two digits is decimal' => ['1,23', 1.23],
    'negative' => ['-1.234,56', -1234.56],
    'explicit plus' => ['+1 234,56', 1234.56],
    'trailing currency code' => ['1 234,56 EUR', 1234.56],
    'leading currency symbol' => ['€1,234.56', 1234.56],
    'trailing currency symbol' => ['1.234,56 Kč', 1234.56],
    'zero' => ['0,00', 0.0],
]);

it('tells an unreadable amount apart from a zero', function (): void {
    expect(AmountString::parse(null))->toBeNull()
        ->and(AmountString::parse(''))->toBeNull()
        ->and(AmountString::parse('  '))->toBeNull()
        ->and(AmountString::parse('n/a'))->toBeNull()
        ->and(AmountString::parse('EUR'))->toBeNull()
        ->and(AmountString::parse('0'))->toBe(0.0);
});

it('falls back for callers that treat an unreadable amount as nothing', function (): void {
    expect(AmountString::toFloat('n/a'))->toBe(0.0)
        ->and(AmountString::toFloat(null))->toBe(0.0)
        ->and(AmountString::toFloat('n/a', -1.0))->toBe(-1.0)
        ->and(AmountString::toFloat('1.234,56'))->toBe(1234.56);
});
