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
    $user = createSaasUser();
    subscribeToPaidPlan($user);
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

it('filters by taxable supply date when period_basis is tax', function (): void {
    $user = createSaasUser();
    subscribeToPaidPlan($user);
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
    $user = createSaasUser();
    subscribeToPaidPlan($user);

    $this->actingAs($user)
        ->getJson('/api/v1/invoices/export/csv?date_from=2026-01-01&date_to=2026-12-31&types[]=proforma')
        ->assertUnprocessable();
});

it('requires date_from and date_to', function (): void {
    $user = createSaasUser();
    subscribeToPaidPlan($user);

    $this->actingAs($user)
        ->getJson('/api/v1/invoices/export/csv')
        ->assertUnprocessable();
});

it('does not include another account invoices', function (): void {
    $owner = createSaasUser();
    subscribeToPaidPlan($owner);
    $ownerClient = Client::factory()->create(['user_id' => $owner->id]);
    $ownerInvoice = issuedInvoiceFor($owner->id, $ownerClient->id, ['issued_at' => '2026-03-15']);

    $other = createSaasUser();
    subscribeToPaidPlan($other);

    $response = $this->actingAs($other)->get('/api/v1/invoices/export/csv?date_from=2026-01-01&date_to=2026-12-31');

    $response->assertOk();
    expect((string) $response->getContent())->not->toContain($ownerInvoice->invoice_number);
});

/*
 * Formula-injection escaping fires on a leading `-`, which is also how every
 * negative amount begins. A credit note exports with negative subtotal, VAT,
 * total and balance, and escaping all four made them text: opened in Excel or
 * LibreOffice they sat outside any SUM() over the column, so the figure read
 * off the export was too high by exactly the credit notes in it, silently.
 */
it('exports credit note amounts as numbers, not quoted text', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    issuedInvoiceFor($user->id, $client->id, [
        'type' => 'credit_note',
        'invoice_number' => 'DOB-1',
        'subtotal' => '-1000.00',
        'vat_amount' => '-200.00',
        'total' => '-1200.00',
    ]);

    $csv = (string) $this->actingAs($user)->get('/api/v1/invoices/export/csv?date_from=2026-01-01&date_to=2026-12-31')->assertOk()->getContent();

    // Comma decimals, matching the ';' delimiter — see CsvFormulaEscape and
    // InvoiceCsvBuilder::money().
    expect($csv)->toContain(';-1200,00')
        ->and($csv)->not->toContain("'-1200,00")
        ->and($csv)->not->toContain("'-1000,00")
        ->and($csv)->not->toContain("'-200,00");
});

it('still neutralises a formula a client name smuggles into the export', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    // The export reads the snapshot frozen at issue time, which is where a
    // name typed before issuing ends up.
    issuedInvoiceFor($user->id, $client->id, [
        'invoice_number' => 'FA-1',
        'client_snapshot' => ['name' => "=cmd|'/c calc'!A1", 'ico' => '87654321'],
    ]);

    $csv = (string) $this->actingAs($user)->get('/api/v1/invoices/export/csv?date_from=2026-01-01&date_to=2026-12-31')->assertOk()->getContent();

    expect($csv)->toContain("'=cmd|'/c calc'!A1")
        ->and($csv)->not->toContain(';=cmd');
});

/*
 * The ';' delimiter is chosen for the CZ/SK locale, and that is exactly the
 * locale whose decimal separator is a comma — Excel there reads a dot-decimal
 * cell as text, so the column will not sum. The two choices have to agree;
 * this pins that they do, since nothing else in the suite fixed the format.
 */
it('writes amounts with a comma decimal to match the delimiter', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    issuedInvoiceFor($user->id, $client->id, [
        'invoice_number' => 'FA-7',
        'subtotal' => '1000.00',
        'vat_amount' => '230.00',
        'total' => '1230.00',
    ]);

    $csv = (string) $this->actingAs($user)
        ->get('/api/v1/invoices/export/csv?date_from=2026-01-01&date_to=2026-12-31')
        ->assertOk()->getContent();

    expect($csv)->toContain(';1230,00')
        ->and($csv)->not->toContain(';1230.00');
});
