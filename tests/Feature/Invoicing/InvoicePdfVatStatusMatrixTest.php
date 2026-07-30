<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Services\InvoicePdfService;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Enums\VatStatus;

/**
 * @param  array<string, mixed>  $invoiceAttributes
 * @param  array<string, mixed>  $clientAttributes
 * @return array{0: User, 1: Invoice}
 */
function matrixScope(string $vatStatus, array $invoiceAttributes = [], array $clientAttributes = []): array
{
    $user = createUser([
        'ico' => '12345678', 'dic' => 'SK12345678', 'vat_status' => $vatStatus,
        'is_vat_payer' => $vatStatus === 'payer', 'country' => 'SK',
    ]);
    app(VatRateSeederService::class)->seedFor($user);

    $client = Client::factory()->create([
        'user_id' => $user->id,
        'client_type' => 'company',
        'company_name' => 'Klient s.r.o.',
        'country' => 'SK',
        'reverse_charge_allowed' => true,
        ...$clientAttributes,
    ]);

    $invoice = Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'type' => 'invoice',
        'currency' => 'EUR',
        'discount_percent' => null,
        ...$invoiceAttributes,
    ]);

    $invoice->items()->create([
        'description' => 'Konzultácia',
        'quantity' => 1,
        'unit' => 'ks',
        'unit_price' => 100,
        'vat_rate' => ($invoiceAttributes['reverse_charge'] ?? false) ? 0 : ($vatStatus === 'payer' ? 23 : 0),
        'vat_amount' => 0,
        'total_excl_vat' => 100,
        'total_incl_vat' => 100,
        'sort_order' => 0,
    ]);

    $invoice->refresh()->recalculateTotals()->save();

    return [$user, $invoice];
}

function matrixHtml(Invoice $invoice): string
{
    $service = app(InvoicePdfService::class);

    return view('invoices::pdf', ['vm' => $service->viewModel($invoice->loadMissing(
        ['client', 'items', 'user', 'bankAccount', 'relatedInvoice', 'workReportLines'],
    ))])->render();
}

it('shows the tax document title, VAT columns and recap for a payer with no reverse charge', function (): void {
    [, $invoice] = matrixScope('payer');

    $vm = app(InvoicePdfService::class)->viewModel($invoice->loadMissing(['client', 'items', 'user']));

    expect($vm->showVatColumns)->toBeTrue()
        ->and($vm->showVatRecap)->toBeTrue()
        ->and($vm->showTaxableSupplyDate)->toBeTrue()
        ->and($vm->vatNote)->toBeNull()
        ->and($vm->reverseChargeClause)->toBeNull()
        ->and($vm->totalLabel)->toBe(__('invoices::pdf.total_due'))
        ->and($vm->documentTitle)->toBe(__('invoices::pdf.title_tax_document').' '.$invoice->invoice_number);
});

it('hides VAT columns and shows the SK domestic clause for a payer with domestic reverse charge', function (): void {
    [, $invoice] = matrixScope('payer', ['reverse_charge' => true, 'reverse_charge_mode' => 'domestic']);

    $vm = app(InvoicePdfService::class)->viewModel($invoice->loadMissing(['client', 'items', 'user']));

    expect($vm->showVatColumns)->toBeFalse()
        ->and($vm->showVatRecap)->toBeFalse()
        ->and($vm->showTaxableSupplyDate)->toBeTrue()
        ->and($vm->vatNote)->toBeNull()
        ->and($vm->reverseChargeClause)->toBe(__('invoices::pdf.reverse_charge_clause_domestic_sk'))
        ->and($vm->totalLabel)->toBe(__('invoices::pdf.total_excl_vat'))
        ->and($vm->documentTitle)->toBe(__('invoices::pdf.title_tax_document').' '.$invoice->invoice_number);

    $html = matrixHtml($invoice);
    expect($html)->toContain(__('invoices::pdf.reverse_charge_clause_domestic_sk'))
        ->not->toContain(__('invoices::pdf.vat_recap'));
});

it('shows the EU clause for a payer with EU reverse charge', function (): void {
    [, $invoice] = matrixScope('payer', ['reverse_charge' => true, 'reverse_charge_mode' => 'eu']);

    $vm = app(InvoicePdfService::class)->viewModel($invoice->loadMissing(['client', 'items', 'user']));

    expect($vm->reverseChargeClause)->toBe(__('invoices::pdf.reverse_charge_clause_eu'))
        ->and($vm->showVatColumns)->toBeFalse();
});

it('shows the not-vat-payer note, hides VAT columns and DUZP, and prints a plain title for a non-payer', function (): void {
    [, $invoice] = matrixScope('non_payer');

    $vm = app(InvoicePdfService::class)->viewModel($invoice->loadMissing(['client', 'items', 'user']));

    expect($vm->showVatColumns)->toBeFalse()
        ->and($vm->showVatRecap)->toBeFalse()
        ->and($vm->showTaxableSupplyDate)->toBeFalse()
        ->and($vm->vatNote)->toBe(__('invoices::pdf.not_vat_payer'))
        ->and($vm->reverseChargeClause)->toBeNull()
        ->and($vm->totalLabel)->toBe(__('invoices::pdf.total_due'))
        ->and($vm->documentTitle)->toBe(__('invoices::pdf.title_invoice').' '.$invoice->invoice_number);

    $html = matrixHtml($invoice);
    expect($html)->toContain(__('invoices::pdf.not_vat_payer'));
});

it('treats an identified person with a domestic client like a non-payer on the PDF', function (): void {
    [, $invoice] = matrixScope('identified');

    $vm = app(InvoicePdfService::class)->viewModel($invoice->loadMissing(['client', 'items', 'user']));

    expect($vm->showVatColumns)->toBeFalse()
        ->and($vm->showTaxableSupplyDate)->toBeFalse()
        ->and($vm->vatNote)->toBe(__('invoices::pdf.not_vat_payer'))
        ->and($vm->documentTitle)->toBe(__('invoices::pdf.title_invoice').' '.$invoice->invoice_number);
});

it('shows the EU clause and tax document title for an identified person with EU reverse charge', function (): void {
    [, $invoice] = matrixScope('identified', ['reverse_charge' => true, 'reverse_charge_mode' => 'eu']);

    $vm = app(InvoicePdfService::class)->viewModel($invoice->loadMissing(['client', 'items', 'user']));

    expect($vm->showVatColumns)->toBeFalse()
        ->and($vm->showTaxableSupplyDate)->toBeTrue()
        ->and($vm->vatNote)->toBeNull()
        ->and($vm->reverseChargeClause)->toBe(__('invoices::pdf.reverse_charge_clause_eu'))
        ->and($vm->documentTitle)->toBe(__('invoices::pdf.title_tax_document').' '.$invoice->invoice_number);
});

it('does not change PDF rendering when the supplier profile changes after issue (frozen snapshot)', function (): void {
    [$user, $invoice] = matrixScope('payer');
    $invoice->supplier_snapshot = array_merge($invoice->supplier_snapshot ?? [
        'name' => $user->full_name, 'country' => 'SK',
    ], ['vat_status' => 'payer']);
    $invoice->save();

    $before = app(InvoicePdfService::class)->viewModel($invoice->loadMissing(['client', 'items', 'user']));

    $user->update(['vat_status' => 'non_payer']);

    $after = app(InvoicePdfService::class)->viewModel($invoice->refresh()->loadMissing(['client', 'items', 'user']));

    expect($before->showVatColumns)->toBeTrue()
        ->and($after->showVatColumns)->toBeTrue()
        ->and($after->supplierVatStatus)->toBe(VatStatus::Payer);
});
