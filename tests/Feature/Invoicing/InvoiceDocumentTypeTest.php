<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Testing\TestResponse;
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
function createDocument(object $test, User $user, Client $client, array $overrides = []): TestResponse
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

it('numbers proformas in their own PF series', function (): void {
    [$user, $client] = documentScope();

    $invoice = createDocument($this, $user, $client);
    $proforma = createDocument($this, $user, $client, ['type' => 'proforma']);

    $invoice->assertCreated();
    $proforma->assertCreated();

    $year = now()->format('Y');

    expect($invoice->json('invoice_number'))->toBe("FA-{$year}-001")
        ->and($proforma->json('invoice_number'))->toBe("PF-{$year}-001")
        ->and($proforma->json('type'))->toBe('proforma')
        ->and($proforma->json('taxable_supply_at'))->toBeNull();
});

it('defaults the variable symbol and DUZP on creation', function (): void {
    [$user, $client] = documentScope();

    $response = createDocument($this, $user, $client);

    $year = now()->format('Y');

    expect($response->json('variable_symbol'))->toBe("{$year}001")
        ->and($response->json('taxable_supply_at'))->toBe(today()->toDateString());
});

it('creates a dobropis with negated items referencing the original', function (): void {
    [$user, $client] = documentScope();
    $original = issuedInvoiceWithItem($user, $client);

    $response = $this->actingAs($user)->postJson("/api/v1/invoices/{$original->id}/corrective", [
        'type' => 'credit_note',
    ]);

    $response->assertCreated();

    expect($response->json('type'))->toBe('credit_note')
        ->and($response->json('related_invoice_id'))->toBe($original->id)
        ->and($response->json('invoice_number'))->toStartWith('DB-')
        ->and((float) $response->json('items.0.quantity'))->toBe(-2.0)
        ->and((float) $response->json('total'))->toBe(-120.0)
        ->and($original->refresh()->status)->toBe(InvoiceStatus::Sent);
});

it('storno cancels the original invoice', function (): void {
    [$user, $client] = documentScope();
    $original = issuedInvoiceWithItem($user, $client);

    $response = $this->actingAs($user)->postJson("/api/v1/invoices/{$original->id}/corrective", [
        'type' => 'storno',
    ]);

    $response->assertCreated();

    expect($response->json('invoice_number'))->toStartWith('ST-')
        ->and($original->refresh()->status)->toBe(InvoiceStatus::Cancelled);
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
