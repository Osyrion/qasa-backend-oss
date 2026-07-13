<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @param  array<string, mixed>  $userOverrides
 * @return array{0: User, 1: Client}
 */
function maskScope(array $userOverrides = []): array
{
    $user = createUser($userOverrides);
    $client = Client::factory()->create(['user_id' => $user->id]);

    return [$user, $client];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function postInvoice(object $test, User $user, Client $client, array $overrides = []): TestResponse
{
    return $test->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $client->id,
        'issued_at' => today()->toDateString(),
        'due_at' => today()->addDays(14)->toDateString(),
        'currency' => 'EUR',
        ...$overrides,
    ]);
}

/**
 * Numbers are assigned at issue, not at draft creation — create the draft,
 * then issue it and read the number off the issue response.
 *
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function postAndIssueInvoice(object $test, User $user, Client $client, array $overrides = []): TestResponse
{
    $draft = postInvoice($test, $user, $client, $overrides);
    $draft->assertCreated();

    return $test->actingAs($user)->postJson("/api/v1/invoices/{$draft->json('id')}/status", [
        'status' => 'issued',
    ]);
}

it('numbers invoices using the configured mask, independently per document type', function (): void {
    [$user, $client] = maskScope(['invoice_number_mask' => '{YYYY}{NNNN}']);
    $year = now()->format('Y');

    $first = postAndIssueInvoice($this, $user, $client);
    $second = postAndIssueInvoice($this, $user, $client);
    $proforma = postAndIssueInvoice($this, $user, $client, ['type' => 'proforma']);

    $first->assertOk();
    $second->assertOk();
    $proforma->assertOk();

    expect($first->json('invoice_number'))->toBe("{$year}0001")
        ->and($second->json('invoice_number'))->toBe("{$year}0002")
        ->and($proforma->json('invoice_number'))->toBe("PF-{$year}0001");
});

it('applies invoice_number_start as a floor for a migrated sequence', function (): void {
    [$user, $client] = maskScope([
        'invoice_number_mask' => '{NNNNN}',
        'invoice_number_start' => 501,
    ]);

    $first = postAndIssueInvoice($this, $user, $client);
    $second = postAndIssueInvoice($this, $user, $client);

    $first->assertOk();
    $second->assertOk();

    expect($first->json('invoice_number'))->toBe('00501')
        ->and($second->json('invoice_number'))->toBe('00502');
});

it('keeps the legacy FA-{year}-{seq} format for users without a mask', function (): void {
    [$user, $client] = maskScope();
    $year = now()->format('Y');

    $response = postAndIssueInvoice($this, $user, $client);

    $response->assertOk();
    expect($response->json('invoice_number'))->toBe("FA-{$year}-001");
});

it('clears the mask back to the legacy default when sent as an empty string', function (): void {
    [$user, $client] = maskScope(['invoice_number_mask' => '{YYYY}{NNNN}']);

    $this->actingAs($user)->putJson('/api/v1/auth/profile', [
        'invoice_number_mask' => '',
    ])->assertOk()->assertJsonPath('invoice_number_mask', null);

    $year = now()->format('Y');
    $response = postAndIssueInvoice($this, $user, $client);

    expect($response->json('invoice_number'))->toBe("FA-{$year}-001");
});

it('rejects an invoice number mask without a sequence token', function (): void {
    $user = createUser();

    $this->actingAs($user)->putJson('/api/v1/auth/profile', [
        'invoice_number_mask' => '{YYYY}',
    ])->assertUnprocessable();
});

it('rejects an invoice_number_start below 1', function (): void {
    $user = createUser();

    $this->actingAs($user)->putJson('/api/v1/auth/profile', [
        'invoice_number_start' => 0,
    ])->assertUnprocessable();
});

it('accepts a custom mask and start on profile update and uses them for the next invoice', function (): void {
    [$user, $client] = maskScope();

    $this->actingAs($user)->putJson('/api/v1/auth/profile', [
        'invoice_number_mask' => '{YY}01{NNN}',
    ])->assertOk()->assertJsonPath('invoice_number_mask', '{YY}01{NNN}');

    $response = postAndIssueInvoice($this, $user, $client);

    $shortYear = now()->format('y');
    expect($response->json('invoice_number'))->toBe("{$shortYear}01001");
});
