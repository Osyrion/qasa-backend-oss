<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Models\WaitlistSignup;
use Illuminate\Support\Facades\Http;

it('accepts a valid signup and stores it', function (): void {
    $response = $this->postJson('/api/v1/public/waitlist', [
        'email' => 'lead@example.com',
        'locale' => 'sk',
        'page_variant' => 'sk-peppol',
    ]);

    $response->assertCreated()->assertJsonStructure(['message']);

    $signup = WaitlistSignup::query()->where('email', 'lead@example.com')->firstOrFail();
    expect($signup->locale)->toBe('sk')
        ->and($signup->page_variant)->toBe('sk-peppol');
});

it('accepts the en locale for the mixed CZ+SK English page', function (): void {
    $response = $this->postJson('/api/v1/public/waitlist', [
        'email' => 'foreign-founder@example.com',
        'locale' => 'en',
        'page_variant' => 'en-mixed',
    ]);

    $response->assertCreated();
    expect(WaitlistSignup::query()->where('email', 'foreign-founder@example.com')->value('locale'))->toBe('en');
});

it('rejects an invalid e-mail', function (): void {
    $this->postJson('/api/v1/public/waitlist', [
        'email' => 'not-an-email',
        'locale' => 'cs',
    ])->assertUnprocessable();

    expect(WaitlistSignup::query()->count())->toBe(0);
});

it('is idempotent for a duplicate e-mail and does not leak that it already exists', function (): void {
    $payload = ['email' => 'repeat@example.com', 'locale' => 'cs', 'page_variant' => 'cz-invoicing-vida'];

    $first = $this->postJson('/api/v1/public/waitlist', $payload);
    $second = $this->postJson('/api/v1/public/waitlist', $payload);

    $first->assertCreated();
    $second->assertCreated();
    expect($second->json('message'))->toBe($first->json('message'));

    expect(WaitlistSignup::query()->where('email', 'repeat@example.com')->count())->toBe(1);
});

it('silently drops a submission with a filled honeypot', function (): void {
    $response = $this->postJson('/api/v1/public/waitlist', [
        'email' => 'bot@example.com',
        'locale' => 'sk',
        'honeypot' => 'I am a bot',
    ]);

    $response->assertCreated();
    expect(WaitlistSignup::query()->where('email', 'bot@example.com')->exists())->toBeFalse();
});

it('rejects a signup with a missing Turnstile token once captcha is enabled', function (): void {
    config(['services.turnstile.enabled' => true, 'services.turnstile.secret_key' => 'test-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    $response = $this->postJson('/api/v1/public/waitlist', [
        'email' => 'no-token@example.com',
        'locale' => 'sk',
    ]);

    $response->assertUnprocessable();
    Http::assertNothingSent();
    expect(WaitlistSignup::query()->where('email', 'no-token@example.com')->exists())->toBeFalse();
});

it('rejects a signup when Cloudflare reports the Turnstile token as invalid', function (): void {
    config(['services.turnstile.enabled' => true, 'services.turnstile.secret_key' => 'test-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

    $response = $this->postJson('/api/v1/public/waitlist', [
        'email' => 'bad-token@example.com',
        'locale' => 'sk',
        'turnstile_token' => 'invalid-token',
    ]);

    $response->assertUnprocessable();
    expect(WaitlistSignup::query()->where('email', 'bad-token@example.com')->exists())->toBeFalse();
});

it('accepts a signup with a valid Turnstile token once captcha is enabled', function (): void {
    config(['services.turnstile.enabled' => true, 'services.turnstile.secret_key' => 'test-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    $response = $this->postJson('/api/v1/public/waitlist', [
        'email' => 'good-token@example.com',
        'locale' => 'sk',
        'turnstile_token' => 'valid-token',
    ]);

    $response->assertCreated();
    expect(WaitlistSignup::query()->where('email', 'good-token@example.com')->exists())->toBeTrue();
});
