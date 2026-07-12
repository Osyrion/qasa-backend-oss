<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Mail\QuoteDecisionMail;
use App\Modules\Invoicing\Domain\Models\Quote;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * @return array{0: User, 1: Quote}
 */
function decidableQuote(): array
{
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id, 'email' => 'klient@example.com']);

    $quote = Quote::factory()->sent()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'valid_until' => now()->addDays(10)->toDateString(),
        'supplier_snapshot' => ['name' => 'Dodávateľ'],
        'client_snapshot' => ['name' => 'Klient', 'email' => 'klient@example.com'],
    ]);
    $quote->forceFill(['public_token' => Str::random(64)])->save();

    return [$user, $quote];
}

it('accepts a sent quote via the public token and notifies the owner', function (): void {
    Mail::fake();

    [$user, $quote] = decidableQuote();

    $response = $this->postJson("/api/v1/public/quotes/{$quote->public_token}/accept", [
        'decision_note' => 'Súhlasím s ponukou.',
    ]);

    $response->assertOk();
    expect($response->json('status'))->toBe('accepted');

    $quote->refresh();
    expect($quote->status)->toBe('accepted')
        ->and($quote->accepted_at)->not->toBeNull()
        ->and($quote->decision_note)->toBe('Súhlasím s ponukou.')
        ->and($quote->decision_ip)->not->toBeNull();

    Mail::assertQueued(
        QuoteDecisionMail::class,
        fn (QuoteDecisionMail $mail): bool => $mail->hasTo($user->email) && $mail->quote->is($quote),
    );
});

it('rejects a sent quote via the public token', function (): void {
    Mail::fake();

    [, $quote] = decidableQuote();

    $this->postJson("/api/v1/public/quotes/{$quote->public_token}/reject")->assertOk();

    expect($quote->refresh()->status)->toBe('rejected')
        ->and($quote->rejected_at)->not->toBeNull();

    Mail::assertQueued(QuoteDecisionMail::class);
});

it('rejects a second decision on an already-decided quote', function (): void {
    [, $quote] = decidableQuote();

    $this->postJson("/api/v1/public/quotes/{$quote->public_token}/accept")->assertOk();

    $this->postJson("/api/v1/public/quotes/{$quote->public_token}/accept")->assertStatus(422);
    $this->postJson("/api/v1/public/quotes/{$quote->public_token}/reject")->assertStatus(422);
});

it('rejects a decision on an expired quote', function (): void {
    [, $quote] = decidableQuote();
    $quote->update(['valid_until' => now()->subDay()->toDateString()]);

    $this->postJson("/api/v1/public/quotes/{$quote->public_token}/accept")->assertStatus(422);
});

it('returns 404 for an unknown quote token', function (): void {
    $this->getJson('/api/v1/public/quotes/does-not-exist')->assertNotFound();
    $this->postJson('/api/v1/public/quotes/does-not-exist/accept')->assertNotFound();
});

it('throttles the public decision endpoints per IP', function (): void {
    [, $quote] = decidableQuote();

    for ($i = 0; $i < 10; $i++) {
        $this->postJson("/api/v1/public/quotes/{$quote->public_token}/reject");
    }

    $this->postJson("/api/v1/public/quotes/{$quote->public_token}/reject")->assertStatus(429);
});

it('exposes can_decide only while sent and unexpired', function (): void {
    [, $quote] = decidableQuote();

    $payload = $this->getJson("/api/v1/public/quotes/{$quote->public_token}")->json();
    expect($payload['can_decide'])->toBeTrue()
        ->and($payload['effective_status'])->toBe('sent');

    $this->postJson("/api/v1/public/quotes/{$quote->public_token}/accept")->assertOk();

    $after = $this->getJson("/api/v1/public/quotes/{$quote->public_token}")->json();
    expect($after['can_decide'])->toBeFalse()
        ->and($after['effective_status'])->toBe('accepted');
});
