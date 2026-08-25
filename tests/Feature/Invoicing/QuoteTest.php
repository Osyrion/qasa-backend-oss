<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Mail\QuoteEmail;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Shared\Enums\VatStatus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function quotePayload(string $clientId, array $overrides = []): array
{
    return array_merge([
        'client_id' => $clientId,
        'issued_at' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'currency' => 'EUR',
    ], $overrides);
}

it('creates a quote with a default-masked number', function (): void {
    $user = createSaasUser();
    subscribeToPaidPlan($user);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson('/api/v1/quotes', quotePayload($client->id));

    $response->assertCreated();

    expect($response->json('data.status'))->toBe('draft')
        ->and($response->json('data.quote_number'))->toStartWith('CP-'.now()->format('Y').'-');

    $this->assertDatabaseHas('quotes', [
        'id' => $response->json('data.id'),
        'client_id' => $client->id,
        'user_id' => $user->id,
    ]);
});

it('generates sequential quote numbers for consecutive creates', function (): void {
    $user = createSaasUser();
    subscribeToPaidPlan($user);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $first = $this->actingAs($user)->postJson('/api/v1/quotes', quotePayload($client->id));
    $second = $this->actingAs($user)->postJson('/api/v1/quotes', quotePayload($client->id));

    $first->assertCreated();
    $second->assertCreated();

    $firstNumber = (int) Str::afterLast((string) $first->json('data.quote_number'), '-');
    $secondNumber = (int) Str::afterLast((string) $second->json('data.quote_number'), '-');

    expect($secondNumber)->toBe($firstNumber + 1);
});

it('uses the tenant custom quote number mask and start', function (): void {
    $user = createSaasUser(['quote_number_mask' => 'Q{YYYY}{NNNN}', 'quote_number_start' => 500]);
    subscribeToPaidPlan($user);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson('/api/v1/quotes', quotePayload($client->id));

    $response->assertCreated();
    expect($response->json('data.quote_number'))->toBe('Q'.now()->format('Y').'0500');
});

it('recalculates totals when items are added and removed', function (): void {
    $user = createSaasUser(['country' => 'SK']);
    subscribeToPaidPlan($user);
    app(VatRateSeederService::class)->seedFor($user);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $quote = $this->actingAs($user)->postJson('/api/v1/quotes', quotePayload($client->id))->json('data');

    $item = $this->actingAs($user)->postJson("/api/v1/quotes/{$quote['id']}/items", [
        'description' => 'Konzultácia',
        'quantity' => 2,
        'unit' => 'hod',
        'unit_price' => 50,
        'vat_rate' => 23,
    ]);
    $item->assertCreated();

    $afterAdd = $this->actingAs($user)->getJson("/api/v1/quotes/{$quote['id']}")->json('data');
    expect((float) $afterAdd['subtotal'])->toBe(100.0)
        ->and((float) $afterAdd['vat_amount'])->toBe(23.0)
        ->and((float) $afterAdd['total'])->toBe(123.0);

    $this->actingAs($user)
        ->deleteJson("/api/v1/quotes/{$quote['id']}/items/{$item->json('data.id')}")
        ->assertNoContent();

    $afterRemove = $this->actingAs($user)->getJson("/api/v1/quotes/{$quote['id']}")->json('data');
    expect((float) $afterRemove['subtotal'])->toBe(0.0)
        ->and((float) $afterRemove['total'])->toBe(0.0);
});

it('rejects editing a sent quote', function (): void {
    $user = createSaasUser();
    subscribeToPaidPlan($user);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $quote = Quote::factory()->sent()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $this->actingAs($user)
        ->putJson("/api/v1/quotes/{$quote->id}", quotePayload($client->id))
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson("/api/v1/quotes/{$quote->id}/items", [
            'description' => 'x', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 10, 'vat_rate' => 0,
        ])
        ->assertForbidden();
});

it('does not let a user access another account quote', function (): void {
    $victim = createSaasUser();
    subscribeToPaidPlan($victim);
    $victimClient = Client::factory()->create(['user_id' => $victim->id]);
    $victimQuote = Quote::factory()->draft()->create(['user_id' => $victim->id, 'client_id' => $victimClient->id]);

    $attacker = createSaasUser();
    subscribeToPaidPlan($attacker);

    $this->actingAs($attacker)->getJson("/api/v1/quotes/{$victimQuote->id}")->assertNotFound();
    $this->actingAs($attacker)->deleteJson("/api/v1/quotes/{$victimQuote->id}")->assertNotFound();
});

it('rejects creating a quote for a foreign client', function (): void {
    $user = createSaasUser();
    subscribeToPaidPlan($user);
    $stranger = createSaasUser();
    subscribeToPaidPlan($stranger);
    $strangerClient = Client::factory()->create(['user_id' => $stranger->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/quotes', quotePayload($strangerClient->id))
        ->assertStatus(422);
});

it('deletes only a draft quote', function (): void {
    $user = createSaasUser();
    subscribeToPaidPlan($user);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $draft = Quote::factory()->draft()->create(['user_id' => $user->id, 'client_id' => $client->id]);
    $this->actingAs($user)->deleteJson("/api/v1/quotes/{$draft->id}")->assertNoContent();
    $this->assertSoftDeleted('quotes', ['id' => $draft->id]);

    $sent = Quote::factory()->sent()->create(['user_id' => $user->id, 'client_id' => $client->id]);
    $this->actingAs($user)->deleteJson("/api/v1/quotes/{$sent->id}")->assertForbidden();
});

// ── Email ─────────────────────────────────────────────────────────────────

/**
 * @param  array<string, mixed>  $quoteAttributes
 * @param  array<string, mixed>  $clientAttributes
 * @return array{0: User, 1: Quote, 2: Client}
 */
function emailableQuote(array $quoteAttributes = [], array $clientAttributes = []): array
{
    $user = createSaasUser();
    subscribeToPaidPlan($user);
    $client = Client::factory()->create([
        'user_id' => $user->id,
        'email' => 'klient@example.com',
        'locale' => 'sk',
        ...$clientAttributes,
    ]);

    $quote = Quote::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'currency' => 'EUR',
        ...$quoteAttributes,
    ]);

    return [$user, $quote, $client];
}

it('freezes the supplier vat_status on the quote snapshot, not just is_vat_payer', function (): void {
    Mail::fake();

    // An identified person holds a VAT ID but is not a payer, so the
    // deprecated is_vat_payer boolean cannot tell it apart from a non-payer.
    // The quote snapshot used to store only that boolean, and QuotePdfService
    // then reconstructed the status with VatStatus::fromLegacyBool() — which
    // printed an identified supplier as a plain non-payer.
    [$user, $quote] = emailableQuote();
    $user->forceFill(['vat_status' => VatStatus::Identified->value, 'is_vat_payer' => false])->save();

    $this->actingAs($user)->postJson("/api/v1/quotes/{$quote->id}/email")->assertOk();

    $snapshot = $quote->refresh()->supplier_snapshot;

    expect($snapshot['vat_status'] ?? null)->toBe(VatStatus::Identified->value)
        ->and($snapshot['is_vat_payer'] ?? null)->toBeFalse();
});

it('sends a draft quote and freezes the snapshot on draft -> sent', function (): void {
    Mail::fake();

    [$user, $quote] = emailableQuote();

    $response = $this->actingAs($user)->postJson("/api/v1/quotes/{$quote->id}/email");

    $response->assertOk();

    $quote->refresh();

    expect($quote->status)->toBe('sent')
        ->and($quote->supplier_snapshot)->not->toBeNull()
        ->and($quote->supplier_snapshot['name'] ?? null)->toBe($user->full_name)
        ->and($quote->client_snapshot['email'] ?? null)->toBe('klient@example.com')
        ->and($quote->emailed_at)->not->toBeNull()
        ->and($quote->emailed_to)->toBe('klient@example.com')
        ->and($quote->public_token)->not->toBeNull();

    Mail::assertQueued(
        QuoteEmail::class,
        fn (QuoteEmail $mail): bool => $mail->hasTo('klient@example.com')
            && $mail->quote->is($quote)
            && $mail->publicUrl !== null,
    );
});

it('rejects a client without an email and leaves the draft untouched', function (): void {
    Mail::fake();

    [$user, $quote] = emailableQuote(clientAttributes: ['email' => null]);

    $this->actingAs($user)
        ->postJson("/api/v1/quotes/{$quote->id}/email")
        ->assertUnprocessable();

    expect($quote->refresh()->status)->toBe('draft')
        ->and($quote->emailed_at)->toBeNull();

    Mail::assertNothingQueued();
});

it('honours a recipient override and custom message', function (): void {
    Mail::fake();

    [$user, $quote] = emailableQuote();

    $this->actingAs($user)->postJson("/api/v1/quotes/{$quote->id}/email", [
        'to' => 'override@example.com',
        'message' => 'Vlastný text.',
    ])->assertOk();

    expect($quote->refresh()->emailed_to)->toBe('override@example.com');

    Mail::assertQueued(
        QuoteEmail::class,
        fn (QuoteEmail $mail): bool => $mail->hasTo('override@example.com')
            && $mail->customMessage === 'Vlastný text.',
    );
});

// ── PDF ───────────────────────────────────────────────────────────────────

it('downloads a quote PDF document', function (): void {
    $user = createSaasUser(['country' => 'SK']);
    subscribeToPaidPlan($user);
    $client = Client::factory()->create(['user_id' => $user->id, 'locale' => 'sk']);
    $quote = Quote::factory()->create(['user_id' => $user->id, 'client_id' => $client->id, 'currency' => 'EUR']);

    $response = $this->actingAs($user)->get("/api/v1/quotes/{$quote->id}/pdf/download");

    $response->assertOk();

    $content = $response->baseResponse instanceof StreamedResponse
        ? $response->streamedContent()
        : $response->getContent();

    expect(substr((string) $content, 0, 4))->toBe('%PDF');
});
