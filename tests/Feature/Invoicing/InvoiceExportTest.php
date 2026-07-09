<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;

/**
 * @param  array<string, mixed>  $attributes
 */
function issuedInvoiceFor(string $userId, string $clientId, array $attributes = []): Invoice
{
    $invoice = Invoice::factory()->sent()->create([
        'user_id' => $userId,
        'client_id' => $clientId,
        'type' => 'invoice',
        'currency' => 'EUR',
        'exchange_rate_snapshot' => 25.0,
        'discount_percent' => null,
        'client_snapshot' => ['name' => 'Klient', 'ico' => '87654321'],
        'supplier_snapshot' => ['name' => 'Dodávateľ', 'ico' => '12345678', 'country' => 'SK'],
        ...$attributes,
    ]);

    $invoice->items()->create([
        'description' => 'Položka',
        'quantity' => 1,
        'unit' => 'ks',
        'unit_price' => 100,
        'vat_rate' => 23,
        'vat_amount' => 23,
        'total_excl_vat' => 100,
        'total_incl_vat' => 123,
        'sort_order' => 0,
    ]);

    return $invoice->refresh();
}

it('exports invoices as CSV for a given period, excluding drafts and proforma', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $inPeriod = issuedInvoiceFor($user->id, $client->id, ['issued_at' => '2026-03-15']);
    issuedInvoiceFor($user->id, $client->id, ['issued_at' => '2025-01-01']); // out of period
    Invoice::factory()->draft()->create(['user_id' => $user->id, 'client_id' => $client->id, 'issued_at' => '2026-03-10']); // draft excluded
    issuedInvoiceFor($user->id, $client->id, ['issued_at' => '2026-03-20', 'type' => 'proforma']); // proforma excluded by default

    $response = $this->actingAs($user)->get('/api/v1/invoices/export/csv?date_from=2026-01-01&date_to=2026-12-31');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');

    $csv = (string) $response->getContent();
    $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));

    expect($lines)->toHaveCount(2) // header + the one matching invoice
        ->and($csv)->toContain($inPeriod->invoice_number);
});

it('exports invoices as a Pohoda XML dataPack for a given period', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    issuedInvoiceFor($user->id, $client->id, ['issued_at' => '2026-03-15']);
    issuedInvoiceFor($user->id, $client->id, ['issued_at' => '2026-04-01']);

    $response = $this->actingAs($user)->get('/api/v1/invoices/export/pohoda?date_from=2026-01-01&date_to=2026-12-31');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $xml = (string) $response->getContent();
    libxml_use_internal_errors(true);
    $dom = new DOMDocument;
    $loaded = $dom->loadXML($xml);
    libxml_clear_errors();

    expect($loaded)->toBeTrue()
        ->and(substr_count($xml, '<inv:invoice '))->toBe(2);
});

it('filters by taxable supply date when period_basis is tax', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    issuedInvoiceFor($user->id, $client->id, [
        'issued_at' => '2025-12-30',
        'taxable_supply_at' => '2026-01-05',
    ]);

    $response = $this->actingAs($user)->get(
        '/api/v1/invoices/export/csv?date_from=2026-01-01&date_to=2026-12-31&period_basis=tax'
    );

    $response->assertOk();
    $lines = array_values(array_filter(explode("\n", trim(substr((string) $response->getContent(), 3)))));

    expect($lines)->toHaveCount(2); // header + the one invoice whose DUZP falls in the period
});

it('rejects an unsupported document type', function (): void {
    $user = createUser();

    $this->actingAs($user)
        ->getJson('/api/v1/invoices/export/csv?date_from=2026-01-01&date_to=2026-12-31&types[]=proforma')
        ->assertUnprocessable();
});

it('requires date_from and date_to', function (): void {
    $user = createUser();

    $this->actingAs($user)
        ->getJson('/api/v1/invoices/export/csv')
        ->assertUnprocessable();
});

it('does not include another account invoices', function (): void {
    $owner = createUser();
    $ownerClient = Client::factory()->create(['user_id' => $owner->id]);
    $ownerInvoice = issuedInvoiceFor($owner->id, $ownerClient->id, ['issued_at' => '2026-03-15']);

    $other = createUser();

    $response = $this->actingAs($other)->get('/api/v1/invoices/export/csv?date_from=2026-01-01&date_to=2026-12-31');

    $response->assertOk();
    expect((string) $response->getContent())->not->toContain($ownerInvoice->invoice_number);
});
