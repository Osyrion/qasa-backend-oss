<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/** @return array{0: User, 1: Client} */
function documentScope(): array
{
    $user = createUser(['invoice_prefix' => 'FA']);
    $client = Client::factory()->create(['user_id' => $user->id]);

    return [$user, $client];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function createDocument(TestCase $test, User $user, Client $client, array $overrides = []): TestResponse
{
    return $test->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $client->id,
        'issued_at' => today()->toDateString(),
        'due_at' => today()->addDays(14)->toDateString(),
        'currency' => 'EUR',
        ...$overrides,
    ]);
}

function issuedInvoiceWithItem(User $user, Client $client): Invoice
{
    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'type' => 'invoice',
        'status' => 'sent',
        'currency' => 'EUR',
        'discount_percent' => null,
    ]);

    $invoice->items()->create([
        'description' => 'Práce',
        'quantity' => 2,
        'unit' => 'hod',
        'unit_price' => 50,
        'vat_rate' => 20,
        'vat_amount' => 20,
        'total_excl_vat' => 100,
        'total_incl_vat' => 120,
        'sort_order' => 0,
    ]);

    return $invoice->refresh()->recalculateTotals();
}

/** @return TestResponse<Response> */
function issueDocument(TestCase $test, User $user, string $invoiceId): TestResponse
{
    return $test->actingAs($user)->postJson("/api/v1/invoices/{$invoiceId}/status", [
        'status' => 'issued',
    ]);
}

it('creates drafts without a number, assigned only once issued, in their own per-type series', function (): void {
    [$user, $client] = documentScope();

    $invoiceDraft = createDocument($this, $user, $client);
    $proformaDraft = createDocument($this, $user, $client, ['type' => 'proforma']);

    $invoiceDraft->assertCreated();
    $proformaDraft->assertCreated();

    expect($invoiceDraft->json('data.invoice_number'))->toBeNull()
        ->and($proformaDraft->json('data.invoice_number'))->toBeNull()
        ->and($proformaDraft->json('data.type'))->toBe('proforma')
        ->and($proformaDraft->json('data.taxable_supply_at'))->toBeNull();

    $invoice = issueDocument($this, $user, $invoiceDraft->json('data.id'));
    $proforma = issueDocument($this, $user, $proformaDraft->json('data.id'));

    $year = now()->format('Y');

    expect($invoice->json('data.invoice_number'))->toBe("FA-{$year}-001")
        ->and($proforma->json('data.invoice_number'))->toBe("PF-{$year}-001");
});

it('defaults the variable symbol and DUZP at issue, not on creation', function (): void {
    [$user, $client] = documentScope();

    $draft = createDocument($this, $user, $client);

    expect($draft->json('data.variable_symbol'))->toBeNull();

    $response = issueDocument($this, $user, $draft->json('data.id'));

    $year = now()->format('Y');

    expect($response->json('data.variable_symbol'))->toBe("{$year}001")
        ->and($response->json('data.taxable_supply_at'))->toBe(today()->toDateString());
});

it('creates a dobropis with negated items referencing the original', function (): void {
    [$user, $client] = documentScope();
    $original = issuedInvoiceWithItem($user, $client);

    $response = $this->actingAs($user)->postJson("/api/v1/invoices/{$original->id}/corrective", [
        'type' => 'credit_note',
    ]);

    $response->assertCreated();

    expect($response->json('data.type'))->toBe('credit_note')
        ->and($response->json('data.related_invoice_id'))->toBe($original->id)
        ->and($response->json('data.invoice_number'))->toBeNull()
        ->and((float) $response->json('data.items.0.quantity'))->toBe(-2.0)
        ->and((float) $response->json('data.total'))->toBe(-120.0)
        ->and($original->refresh()->status)->toBe('sent');
});

it('storno cancels the original invoice', function (): void {
    [$user, $client] = documentScope();
    $original = issuedInvoiceWithItem($user, $client);

    $response = $this->actingAs($user)->postJson("/api/v1/invoices/{$original->id}/corrective", [
        'type' => 'storno',
    ]);

    $response->assertCreated();

    expect($response->json('data.invoice_number'))->toBeNull()
        ->and($original->refresh()->status)->toBe('cancelled');
});

it('rejects a corrective document for a draft invoice', function (): void {
    [$user, $client] = documentScope();

    $draft = Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'type' => 'invoice',
    ]);

    $this->actingAs($user)->postJson("/api/v1/invoices/{$draft->id}/corrective", [
        'type' => 'credit_note',
    ])->assertUnprocessable();
});

it('rejects a corrective document for a proforma', function (): void {
    [$user, $client] = documentScope();

    $proforma = Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'type' => 'proforma',
        'status' => 'sent',
    ]);

    $this->actingAs($user)->postJson("/api/v1/invoices/{$proforma->id}/corrective", [
        'type' => 'storno',
    ])->assertUnprocessable();
});
