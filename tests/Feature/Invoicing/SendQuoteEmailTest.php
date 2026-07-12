<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Mail\QuoteEmail;
use App\Modules\Invoicing\Domain\Models\Quote;
use Illuminate\Support\Facades\Mail;

/**
 * @param  array<string, mixed>  $quoteAttributes
 * @param  array<string, mixed>  $clientAttributes
 * @return array{0: User, 1: Quote, 2: Client}
 */
function emailableQuote(array $quoteAttributes = [], array $clientAttributes = []): array
{
    $user = createUser();
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

it('sends a draft quote and freezes the snapshot on draft -> sent', function (): void {
    Mail::fake();

    [$user, $quote, $client] = emailableQuote();

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

it('does not change status when emailing an already sent quote', function (): void {
    Mail::fake();

    [$user, $quote] = emailableQuote(['status' => 'sent']);

    $this->actingAs($user)->postJson("/api/v1/quotes/{$quote->id}/email")->assertOk();

    expect($quote->refresh()->status)->toBe('sent');

    Mail::assertQueued(QuoteEmail::class);
});

it('does not overwrite an already frozen snapshot on resend', function (): void {
    Mail::fake();

    [$user, $quote] = emailableQuote([
        'status' => 'sent',
        'supplier_snapshot' => ['name' => 'Frozen Name', 'email' => 'frozen@example.com'],
        'client_snapshot' => ['name' => 'Frozen Client', 'email' => 'klient@example.com'],
    ]);

    $this->actingAs($user)->postJson("/api/v1/quotes/{$quote->id}/email")->assertOk();

    expect($quote->refresh()->supplier_snapshot['name'] ?? null)->toBe('Frozen Name');
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
