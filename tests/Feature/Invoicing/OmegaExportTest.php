<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoiceVatLine;
use App\Modules\Invoicing\Domain\Services\OmegaExportBuilder;

/**
 * @return array{0: User, 1: Invoice}
 */
function omegaIssuedInvoice(): array
{
    $user = createUser([
        'ico' => '12345678', 'dic' => '2020202020', 'vat_id' => 'SK2020202020',
        'is_vat_payer' => true, 'vat_status' => 'payer', 'country' => 'SK',
    ]);

    $client = Client::factory()->create([
        'user_id' => $user->id, 'client_type' => 'company', 'company_name' => 'Klient s.r.o.',
        'country' => 'SK', 'ico' => '87654321',
    ]);

    $invoice = Invoice::factory()->sent()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'type' => 'invoice',
        'currency' => 'EUR',
        'variable_symbol' => '2026001',
        'discount_percent' => null,
        'issued_at' => '2026-01-10',
        'due_at' => '2026-01-24',
        'taxable_supply_at' => '2026-01-10',
        'client_snapshot' => [
            'name' => 'Klient s.r.o.', 'ico' => '87654321', 'dic' => '3030303030',
            'address' => 'Nová 5', 'city' => 'Košice', 'postal_code' => '04001',
        ],
    ]);

    $invoice->items()->create([
        'description' => 'Konzultácia', 'quantity' => 10, 'unit' => 'hod',
        'unit_price' => 50, 'vat_rate' => 23, 'vat_amount' => 115,
        'total_excl_vat' => 500, 'total_incl_vat' => 615, 'sort_order' => 0,
    ]);

    $invoice->refresh()->recalculateTotals()->save();

    return [$user, $invoice->refresh()->load('items')];
}

/**
 * @return array{0: User, 1: SupplierInvoice}
 */
function omegaReceivedInvoice(): array
{
    $user = createUser(['ico' => '12345678', 'country' => 'SK']);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $supplierInvoice = SupplierInvoice::factory()->received()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'supplier_invoice_number' => 'FA2026-99',
        'variable_symbol' => '999',
        'issued_at' => '2026-01-05',
        'due_at' => '2026-01-19',
        'currency' => 'EUR',
        'subtotal' => 100,
        'vat_amount' => 23,
        'total' => 123,
        'vendor_snapshot' => [
            'name' => 'Dodávateľ s.r.o.', 'ico' => '11112222', 'dic' => '4040404040',
            'address' => 'Stará 1', 'city' => 'Žilina', 'postal_code' => '01001',
        ],
    ]);

    SupplierInvoiceVatLine::factory()->create([
        'supplier_invoice_id' => $supplierInvoice->id,
        'vat_rate' => 23, 'base' => 100, 'vat_amount' => 23, 'sort_order' => 0,
    ]);

    return [$user, $supplierInvoice->refresh()->load('vatLines')];
}

it('builds windows-1250 encoded R01/R02 rows for an issued invoice', function (): void {
    [, $invoice] = omegaIssuedInvoice();

    $content = app(OmegaExportBuilder::class)->buildIssued([$invoice]);
    $decoded = iconv('Windows-1250', 'UTF-8', $content);

    expect($decoded)->toContain('R01;T01;'.$invoice->invoice_number.';2026001;2026-01-10;2026-01-24;2026-01-10;87654321;3030303030;Klient s.r.o.;Nová 5;Košice;04001;EUR;615.00')
        ->and($decoded)->toContain('R02;3;23.00;500.00;115.00;615.00');
});

it('builds R01/R02 rows for a received (supplier) invoice from its VAT lines', function (): void {
    [, $supplierInvoice] = omegaReceivedInvoice();

    $content = app(OmegaExportBuilder::class)->buildReceived([$supplierInvoice]);
    $decoded = iconv('Windows-1250', 'UTF-8', $content);

    expect($decoded)->toContain('R01;T01;FA2026-99;999;2026-01-05;2026-01-19;;11112222;4040404040;Dodávateľ s.r.o.;Stará 1;Žilina;01001;EUR;123.00')
        ->and($decoded)->toContain('R02;3;23.00;100.00;23.00;123.00');
});

it('produces an empty payload for no documents', function (): void {
    expect(app(OmegaExportBuilder::class)->buildIssued([]))->toBe('')
        ->and(app(OmegaExportBuilder::class)->buildReceived([]))->toBe('');
});

it('downloads the issued-invoices omega export over the API', function (): void {
    [$user, $invoice] = omegaIssuedInvoice();

    $response = $this->actingAs($user)->get('/api/v1/invoices/export/omega?'.http_build_query([
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
    ]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('windows-1250');

    $decoded = iconv('Windows-1250', 'UTF-8', $response->getContent());
    expect($decoded)->toContain($invoice->invoice_number);
});

it('downloads the received-invoices omega export over the API', function (): void {
    [$user, $supplierInvoice] = omegaReceivedInvoice();

    $response = $this->actingAs($user)->get('/api/v1/supplier-invoices/export/omega?'.http_build_query([
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
    ]));

    $response->assertOk();
    $decoded = iconv('Windows-1250', 'UTF-8', $response->getContent());
    expect($decoded)->toContain($supplierInvoice->supplier_invoice_number);
});

it('never includes draft invoices in the issued omega export', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'issued_at' => '2026-01-15',
    ]);

    $response = $this->actingAs($user)->get('/api/v1/invoices/export/omega?'.http_build_query([
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
    ]));

    $response->assertOk();
    expect($response->getContent())->toBe('');
});
