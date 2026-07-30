<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Shared\Support\WebhookUrlGuard;

/*
|--------------------------------------------------------------------------
| Regression tests for the security-audit hardening.
|--------------------------------------------------------------------------
*/

// ── Ownership freeze (HasUserScope) ─────────────────────────────────────────

it('refuses to change the owning user_id of a scoped record', function (): void {
    $owner = createUser();
    $attacker = createUser();

    $client = asAccount($owner, fn () => Client::factory()->create(['user_id' => $owner->id]));

    expect(fn () => $client->update(['user_id' => $attacker->id]))
        ->toThrow(RuntimeException::class);

    asAccount($owner, fn () => expect($client->refresh()->user_id)->toBe($owner->id));
});

it('still allows non-ownership updates on a scoped record', function (): void {
    $owner = createUser();
    $client = Client::factory()->create(['user_id' => $owner->id, 'city' => 'Bratislava']);

    $client->update(['city' => 'Košice']);

    expect($client->refresh()->city)->toBe('Košice');
});

// ── Password policy ─────────────────────────────────────────────────────────

it('rejects registration with a password below the strength policy', function (): void {
    config()->set('qasa.features.registration', true);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ján',
        'surname' => 'Novák',
        'email' => 'weak@example.com',
        'password' => 'short',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('rejects a password with no digits', function (): void {
    config()->set('qasa.features.registration', true);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ján',
        'surname' => 'Novák',
        'email' => 'nodigits@example.com',
        'password' => 'onlyletters',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('accepts a policy-compliant password', function (): void {
    config()->set('qasa.features.registration', true);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ján',
        'surname' => 'Novák',
        'email' => 'strong@example.com',
        'password' => 'super-secret-1',
    ])->assertCreated();
});

// ── SSRF guard (send-time re-resolution) ────────────────────────────────────

it('treats private and reserved addresses as unsafe webhook targets', function (): void {
    expect(WebhookUrlGuard::resolveSafeIp('https://127.0.0.1/hook'))->toBeNull()
        ->and(WebhookUrlGuard::resolveSafeIp('https://169.254.169.254/latest/meta-data'))->toBeNull()
        ->and(WebhookUrlGuard::resolveSafeIp('https://10.0.0.5/hook'))->toBeNull()
        ->and(WebhookUrlGuard::resolveSafeIp('https://[::1]/hook'))->toBeNull()
        ->and(WebhookUrlGuard::resolveSafeIp('ftp://example.com/hook'))->toBeNull();
});

it('returns the vetted public IP to pin the connection to', function (): void {
    expect(WebhookUrlGuard::resolveSafeIp('https://93.184.216.34/hook'))->toBe('93.184.216.34');
});
