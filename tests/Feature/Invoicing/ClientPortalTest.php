<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\QuoteStatus;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\Quote;

/**
 * The portal is unauthenticated, so every test here is really asking one
 * question: can a link reach something it should not? Two things stop it —
 * the row-level policy scopes the *account*, and the controller scopes the
 * *client*. The policy cannot do the second, which is why the second is the
 * one worth testing hardest.
 */
function issuePortalLink(User $owner, Client $client): string
{
    $response = test()->actingAs($owner)
        ->postJson("/api/v1/clients/{$client->id}/portal-link")
        ->assertOk();

    return (string) $response->json('token');
}

it('shows a client only their own invoices, not the whole account', function (): void {
    $owner = createUser();

    $mine = Client::factory()->create(['user_id' => $owner->id]);
    $theirs = Client::factory()->create(['user_id' => $owner->id]);

    $ours = Invoice::factory()->create([
        'user_id' => $owner->id, 'client_id' => $mine->id,
        'status' => InvoiceStatus::Sent->value, 'invoice_number' => 'FA-1',
    ]);
    Invoice::factory()->create([
        'user_id' => $owner->id, 'client_id' => $theirs->id,
        'status' => InvoiceStatus::Sent->value, 'invoice_number' => 'FA-2',
    ]);

    $token = issuePortalLink($owner, $mine);

    $response = $this->getJson("/api/v1/portal/{$token}/invoices")->assertOk();

    // Both invoices belong to the bound account, so the policy returns both —
    // only the client_id filter keeps the other one out.
    $response->assertJsonCount(1, 'data');
    expect($response->json('data.0.id'))->toBe($ours->id);
});

it('hides drafts, which the client was never sent', function (): void {
    $owner = createUser();
    $client = Client::factory()->create(['user_id' => $owner->id]);

    Invoice::factory()->create([
        'user_id' => $owner->id, 'client_id' => $client->id,
        'status' => InvoiceStatus::Draft->value, 'invoice_number' => null,
    ]);

    $token = issuePortalLink($owner, $client);

    $this->getJson("/api/v1/portal/{$token}/invoices")->assertOk()->assertJsonCount(0, 'data');
});

it('summarises what the client still owes', function (): void {
    $owner = createUser();
    $client = Client::factory()->create(['user_id' => $owner->id]);

    Invoice::factory()->overdue()->create([
        'user_id' => $owner->id, 'client_id' => $client->id, 'invoice_number' => 'FA-1',
    ]);

    $token = issuePortalLink($owner, $client);

    $response = $this->getJson("/api/v1/portal/{$token}")->assertOk();

    expect($response->json('summary.invoice_count'))->toBe(1)
        ->and($response->json('summary.overdue_count'))->toBe(1)
        ->and($response->json('summary.outstanding_total'))->not->toBeNull();
});

it('refuses a PDF belonging to another client of the same account', function (): void {
    $owner = createUser();

    $mine = Client::factory()->create(['user_id' => $owner->id]);
    $theirs = Client::factory()->create(['user_id' => $owner->id]);

    $otherInvoice = Invoice::factory()->create([
        'user_id' => $owner->id, 'client_id' => $theirs->id,
        'status' => InvoiceStatus::Sent->value, 'invoice_number' => 'FA-2',
    ]);

    $token = issuePortalLink($owner, $mine);

    // The account is bound, so the row is perfectly visible to the policy —
    // resolving through the client's own invoices is what refuses it.
    $this->get("/api/v1/portal/{$token}/invoices/{$otherInvoice->id}/pdf")->assertNotFound();
});

it('404s an unknown link', function (): void {
    $this->getJson('/api/v1/portal/'.str_repeat('x', 64))->assertNotFound();
});

it('stops resolving once the link is revoked', function (): void {
    $owner = createUser();
    $client = Client::factory()->create(['user_id' => $owner->id]);

    $token = issuePortalLink($owner, $client);
    $this->getJson("/api/v1/portal/{$token}")->assertOk();

    $this->actingAs($owner)->deleteJson("/api/v1/clients/{$client->id}/portal-link")->assertNoContent();

    $this->getJson("/api/v1/portal/{$token}")->assertNotFound();
});

it('returns the same link on a second request, and a different one on regenerate', function (): void {
    $owner = createUser();
    $client = Client::factory()->create(['user_id' => $owner->id]);

    $first = issuePortalLink($owner, $client);
    $second = issuePortalLink($owner, $client);

    expect($second)->toBe($first);

    $regenerated = (string) $this->actingAs($owner)
        ->postJson("/api/v1/clients/{$client->id}/portal-link?regenerate=1")
        ->assertOk()
        ->json('token');

    expect($regenerated)->not->toBe($first);

    // Regeneration is the revocation path: the old link must stop working.
    $this->getJson("/api/v1/portal/{$first}")->assertNotFound();
});

it('never lets one account issue a link for another account client', function (): void {
    $owner = createUser();
    $stranger = createUser();
    $client = asAccount($stranger, fn (): Client => Client::factory()->create(['user_id' => $stranger->id]));

    $this->actingAs($owner)
        ->postJson("/api/v1/clients/{$client->id}/portal-link")
        ->assertNotFound();
});

it('lists the client quotes without offering a way to decide them', function (): void {
    $owner = createUser();
    $client = Client::factory()->create(['user_id' => $owner->id]);
    $otherClient = Client::factory()->create(['user_id' => $owner->id]);

    $mine = Quote::factory()->create([
        'user_id' => $owner->id, 'client_id' => $client->id,
        'status' => QuoteStatus::Sent->value,
    ]);
    Quote::factory()->create([
        'user_id' => $owner->id, 'client_id' => $otherClient->id,
        'status' => QuoteStatus::Sent->value,
    ]);

    $token = issuePortalLink($owner, $client);

    $response = $this->getJson("/api/v1/portal/{$token}/quotes")->assertOk();

    $response->assertJsonCount(1, 'data');
    expect($response->json('data.0.id'))->toBe($mine->id);

    // Accept/reject stays on the per-document link — one decision reachable
    // two ways is a decision whose audit trail depends on the route taken.
    $this->postJson("/api/v1/portal/{$token}/quotes/{$mine->id}/accept")->assertNotFound();
});

it('hides draft quotes from the portal', function (): void {
    $owner = createUser();
    $client = Client::factory()->create(['user_id' => $owner->id]);

    Quote::factory()->create([
        'user_id' => $owner->id, 'client_id' => $client->id,
        'status' => QuoteStatus::Draft->value,
    ]);

    $token = issuePortalLink($owner, $client);

    $this->getJson("/api/v1/portal/{$token}/quotes")->assertOk()->assertJsonCount(0, 'data');
});
