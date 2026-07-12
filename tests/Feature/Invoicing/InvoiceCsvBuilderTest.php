<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Services\InvoiceCsvBuilder;
use App\Modules\Invoicing\Domain\Models\Invoice;

/**
 * @param  array<string, mixed>  $invoiceAttributes
 */
function csvExportInvoice(array $invoiceAttributes = []): Invoice
{
    $user = createUser(['country' => 'SK']);

    $client = Client::factory()->create([
        'user_id' => $user->id,
        'client_type' => 'company',
        'company_name' => 'Klient s.r.o.',
        'ico' => '87654321',
        'dic' => '3030303030',
        'vat_id' => 'SK3030303030',
        'is_vat_payer' => true,
    ]);

    $invoice = Invoice::factory()->sent()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'type' => 'invoice',
        'currency' => 'EUR',
        'variable_symbol' => '2026001',
        'exchange_rate_snapshot' => 25.0,
        'discount_percent' => null,
        'client_snapshot' => [
            'name' => $client->display_name,
            'ico' => $client->ico,
            'dic' => $client->dic,
            'vat_id' => $client->vat_id,
        ],
        ...$invoiceAttributes,
    ]);

    $invoice->items()->create([
        'description' => 'Konzultácia',
        'quantity' => 10,
        'unit' => 'hod',
        'unit_price' => 50,
        'vat_rate' => 23,
        'vat_amount' => 115,
        'total_excl_vat' => 500,
        'total_incl_vat' => 615,
        'sort_order' => 0,
    ]);

    $invoice->refresh()->recalculateTotals()->save();

    $invoice->payments()->create([
        'amount' => 200,
        'paid_at' => now()->toDateString(),
        'method' => 'bank_transfer',
    ]);

    return $invoice->refresh();
}

it('exports one CSV row per invoice with a UTF-8 BOM and semicolon delimiter', function (): void {
    $invoiceA = csvExportInvoice();
    $invoiceB = csvExportInvoice();

    $csv = app(InvoiceCsvBuilder::class)->build([$invoiceA, $invoiceB]);

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF");

    $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));

    expect($lines)->toHaveCount(3) // header + 2 rows
        ->and($lines[0])->toContain(';')
        ->and($lines[0])->toContain(__('invoicing.export.csv_headers.client_dic'));
});

it('includes client tax identifiers from the frozen snapshot', function (): void {
    $invoice = csvExportInvoice();

    $csv = app(InvoiceCsvBuilder::class)->build([$invoice]);

    expect($csv)->toContain('87654321')
        ->toContain('3030303030')
        ->toContain('SK3030303030');
});

it('reports paid amount and balance from recorded payments', function (): void {
    $invoice = csvExportInvoice();

    $csv = app(InvoiceCsvBuilder::class)->build([$invoice]);

    expect($csv)->toContain('200.00')
        ->toContain(number_format($invoice->balance(), 2, '.', ''));
});

it('includes the reverse_charge column with the mode value, blank when not reverse-charged', function (): void {
    $plain = csvExportInvoice();
    $rc = csvExportInvoice(['reverse_charge' => true, 'reverse_charge_mode' => 'eu']);

    $plainRow = explode(';', explode("\n", trim(substr(app(InvoiceCsvBuilder::class)->build([$plain]), 3)))[1]);
    $rcRow = explode(';', explode("\n", trim(substr(app(InvoiceCsvBuilder::class)->build([$rc]), 3)))[1]);

    expect(trim(end($plainRow)))->toBe('')
        ->and(trim(end($rcRow)))->toBe('eu');
});
