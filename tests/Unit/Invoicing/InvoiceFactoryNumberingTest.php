<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Invoice;

/*
 * The factory numbered every document FA-{year}-{random} whatever its type, so
 * a fixture could be a credit note carrying an invoice number — a combination
 * the application never produces (InvoiceType::numberMask() gives each type its
 * own series) and therefore one no test should be asserting against. Anything
 * that reads the series off the number — an export mapping documents to ledger
 * rows, a search, an accountant's eye on a golden file — was being handed a
 * shape production cannot emit.
 */

it('numbers each document type in its own series, the way the generator does', function (InvoiceType $type, string $expectedPrefix): void {
    $invoice = Invoice::factory()->create(['type' => $type->value]);

    expect($invoice->invoice_number)->toStartWith($expectedPrefix.'-');
})->with([
    'invoice' => [InvoiceType::Invoice, 'FA'],
    'proforma' => [InvoiceType::Proforma, 'PF'],
    'credit note' => [InvoiceType::CreditNote, 'DB'],
    'storno' => [InvoiceType::Storno, 'ST'],
]);

it('agrees with the prefix the numbering generator would pick', function (InvoiceType $type): void {
    // The factory reads 'FA' off the users.invoice_prefix column default
    // rather than querying the owner, so this only holds for an account that
    // has not changed it — which is every factory-built account.
    $owner = createUser();
    $invoice = asAccount($owner, fn (): Invoice => Invoice::factory()->create([
        'user_id' => $owner->id,
        'type' => $type->value,
    ]));

    expect($invoice->invoice_number)
        ->toStartWith($type->numberPrefix($owner->invoice_prefix).'-');
})->with(array_map(fn (InvoiceType $type): array => [$type], InvoiceType::cases()));

it('keeps an explicit number the caller passed', function (): void {
    $invoice = Invoice::factory()->create([
        'type' => InvoiceType::Proforma->value,
        'invoice_number' => 'ZAL-2026-0001',
    ]);

    expect($invoice->invoice_number)->toBe('ZAL-2026-0001');
});

it('numbers a document built through a named state', function (): void {
    expect(Invoice::factory()->proforma()->create()->invoice_number)->toStartWith('PF-')
        ->and(Invoice::factory()->creditNote()->create()->invoice_number)->toStartWith('DB-')
        ->and(Invoice::factory()->storno()->create()->invoice_number)->toStartWith('ST-');
});
