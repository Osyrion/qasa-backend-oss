<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @return array{0: User, 1: Client}
 */
function euSalesListScope(): array
{
    // Every invoice here auto-applies EU reverse charge (payer + EU client
    // with a VAT ID), and issuance hard-gates that on a VIES check.
    Http::fake(['ec.europa.eu/*' => Http::response(['isValid' => true, 'name' => 'DE Firma', 'address' => 'Berlin'])]);

    $user = createUser(['country' => 'SK', 'vat_status' => 'payer']);
    app(VatRateSeederService::class)->seedFor($user);
    $client = Client::factory()->create(['user_id' => $user->id, 'country' => 'DE', 'vat_id' => 'DE123456789']);

    return [$user, $client];
}

function issueEuRcInvoice(object $test, User $user, Client $client, string $issuedAt, float $unitPrice = 1000): Invoice
{
    $created = $test->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $client->id,
        'issued_at' => $issuedAt,
        'due_at' => Carbon::parse($issuedAt)->addDays(14)->toDateString(),
        'currency' => 'EUR',
    ])->assertCreated();

    $test->actingAs($user)->postJson("/api/v1/invoices/{$created->json('id')}/items", [
        'description' => 'Služby', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => $unitPrice, 'vat_rate' => 0,
    ])->assertCreated();

    $test->actingAs($user)->postJson("/api/v1/invoices/{$created->json('id')}/status", ['status' => 'sent'])
        ->assertOk();

    return Invoice::withoutGlobalScope('user')->whereKey($created->json('id'))->firstOrFail();
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>|null
 */
function reportRowForPeriod(TestResponse $response, string $period): ?array
{
    foreach ((array) $response->json('data') as $row) {
        if (is_array($row) && ($row['period'] ?? null) === $period) {
            return $row;
        }
    }

    return null;
}

/**
 * @param  TestResponse<Response>  $response
 */
function reportTotalAmount(TestResponse $response): float
{
    $total = 0.0;

    foreach ((array) $response->json('data') as $row) {
        $total += is_array($row) ? (float) ($row['amount'] ?? 0) : 0.0;
    }

    return $total;
}

it('aggregates issued intra-EU reverse-charged invoices by month and client vat id', function (): void {
    [$user, $client] = euSalesListScope();

    issueEuRcInvoice($this, $user, $client, '2026-01-10', 1000);
    issueEuRcInvoice($this, $user, $client, '2026-01-20', 500);
    issueEuRcInvoice($this, $user, $client, '2026-02-05', 300);

    $response = $this->actingAs($user)->getJson('/api/v1/reports/eu-sales-list?year=2026')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and(reportRowForPeriod($response, '2026-01'))
        ->toMatchArray(['vat_id' => 'DE123456789', 'amount' => 1500.0, 'code' => 3])
        ->and((float) (reportRowForPeriod($response, '2026-02')['amount'] ?? 0))->toBe(300.0);
});

it('excludes draft invoices and storno-cancelled documents from the report', function (): void {
    [$user, $client] = euSalesListScope();

    // Draft, never issued.
    $this->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $client->id,
        'issued_at' => '2026-03-01',
        'due_at' => '2026-03-15',
        'currency' => 'EUR',
    ])->assertCreated();

    $sent = issueEuRcInvoice($this, $user, $client, '2026-03-05', 1000);
    issueEuRcInvoice($this, $user, $client, '2026-03-06', 400);

    // Cancels $sent (status -> cancelled) and creates a new draft storno
    // document — neither represents a completed supply for the period.
    $this->actingAs($user)->postJson("/api/v1/invoices/{$sent->id}/corrective", ['type' => 'storno'])
        ->assertCreated();

    $response = $this->actingAs($user)->getJson('/api/v1/reports/eu-sales-list?year=2026&month=3')->assertOk();

    expect(reportTotalAmount($response))->toBe(400.0);
});

it('filters by quarter', function (): void {
    [$user, $client] = euSalesListScope();

    issueEuRcInvoice($this, $user, $client, '2026-02-10', 200);
    issueEuRcInvoice($this, $user, $client, '2026-07-10', 900);

    $response = $this->actingAs($user)->getJson('/api/v1/reports/eu-sales-list?year=2026&quarter=1')->assertOk();

    expect(reportTotalAmount($response))->toBe(200.0);
});

it('uses the frozen client vat_id snapshot, unaffected by a later client change', function (): void {
    [$user, $client] = euSalesListScope();

    issueEuRcInvoice($this, $user, $client, '2026-04-10', 700);

    $client->update(['vat_id' => 'DE999999999']);

    $response = $this->actingAs($user)->getJson('/api/v1/reports/eu-sales-list?year=2026&month=4')->assertOk();

    expect($response->json('data.0.vat_id'))->toBe('DE123456789');
});
