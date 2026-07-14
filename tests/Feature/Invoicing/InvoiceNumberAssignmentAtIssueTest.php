<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @param  array<string, mixed>  $userAttributes
 * @return array{0: User, 1: Client}
 */
function numberingScope(array $userAttributes = []): array
{
    $user = createUser(['invoice_prefix' => 'FA', ...$userAttributes]);
    $client = Client::factory()->create(['user_id' => $user->id]);

    return [$user, $client];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function createDraft(object $test, User $user, Client $client, array $overrides = []): TestResponse
{
    return $test->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $client->id,
        'issued_at' => today()->toDateString(),
        'due_at' => today()->addDays(14)->toDateString(),
        'currency' => 'EUR',
        ...$overrides,
    ]);
}

/** @return TestResponse<Response> */
function issueDraft(object $test, User $user, string $invoiceId, string $status = 'sent'): TestResponse
{
    return $test->actingAs($user)->postJson("/api/v1/invoices/{$invoiceId}/status", [
        'status' => $status,
    ]);
}

it('creates a draft with no invoice number and no variable symbol', function (): void {
    [$user, $client] = numberingScope();

    $draft = createDraft($this, $user, $client);

    $draft->assertCreated();
    expect($draft->json('invoice_number'))->toBeNull()
        ->and($draft->json('variable_symbol'))->toBeNull();

    expect(Invoice::withoutGlobalScope('user')->whereKey($draft->json('id'))->firstOrFail())
        ->invoice_number->toBeNull();
});

it('assigns the number and variable symbol on draft → sent', function (): void {
    [$user, $client] = numberingScope();
    $draft = createDraft($this, $user, $client)->assertCreated();

    $issued = issueDraft($this, $user, $draft->json('id'), 'sent');

    $issued->assertOk();
    $year = now()->format('Y');

    expect($issued->json('invoice_number'))->toBe("FA-{$year}-001")
        ->and($issued->json('variable_symbol'))->not->toBeNull();
});

it('assigns the number and variable symbol on draft → issued too', function (): void {
    [$user, $client] = numberingScope();
    $draft = createDraft($this, $user, $client)->assertCreated();

    $issued = issueDraft($this, $user, $draft->json('id'), 'issued');

    $issued->assertOk();
    $year = now()->format('Y');

    expect($issued->json('invoice_number'))->toBe("FA-{$year}-001")
        ->and($issued->json('variable_symbol'))->not->toBeNull();
});

it('does not leave a gap when an earlier draft is deleted before issuance', function (): void {
    [$user, $client] = numberingScope();

    $draftA = createDraft($this, $user, $client)->assertCreated();
    $draftB = createDraft($this, $user, $client)->assertCreated();

    $this->actingAs($user)->deleteJson("/api/v1/invoices/{$draftA->json('id')}")->assertNoContent();

    $issuedB = issueDraft($this, $user, $draftB->json('id'), 'sent');

    $year = now()->format('Y');
    expect($issuedB->json('invoice_number'))->toBe("FA-{$year}-001");
});

it('numbers documents in issuance order, not creation order', function (): void {
    [$user, $client] = numberingScope();

    $draftA = createDraft($this, $user, $client)->assertCreated();
    $draftB = createDraft($this, $user, $client)->assertCreated();

    $issuedB = issueDraft($this, $user, $draftB->json('id'), 'sent');
    $issuedA = issueDraft($this, $user, $draftA->json('id'), 'sent');

    $year = now()->format('Y');
    expect($issuedB->json('invoice_number'))->toBe("FA-{$year}-001")
        ->and($issuedA->json('invoice_number'))->toBe("FA-{$year}-002");
});

it('never reassigns a number a grandfathered draft already carries', function (): void {
    [$user, $client] = numberingScope();

    // Simulates a draft created before this behavior shipped — the factory
    // (not CreateInvoiceAction) assigns a number directly to a draft.
    $grandfathered = Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'type' => 'invoice',
        'currency' => 'EUR',
        'invoice_number' => 'FA-LEGACY-999',
        'variable_symbol' => null,
        'discount_percent' => null,
        'taxable_supply_at' => null,
    ]);

    $issued = issueDraft($this, $user, $grandfathered->id, 'sent');

    $issued->assertOk();
    expect($issued->json('invoice_number'))->toBe('FA-LEGACY-999');
});
