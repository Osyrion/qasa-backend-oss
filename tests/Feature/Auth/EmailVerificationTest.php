<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

/**
 * The signed verification link is an unauthenticated request that reads
 * `users` by id, and `users` carries a tenant policy since phase 7
 * (docs/plans/POSTGRES_RLS_PLAN.md) — unbound, the row is invisible and a
 * perfectly valid link 404s, which locks the account out of everything
 * behind the `verified` middleware. The signature is what proves the id, so
 * binding from it is safe; see AccountLookup::bindById().
 *
 * asAccount() around the post-request reads because an unauthenticated
 * request leaves the connection cleared (BindTenantContext) — without it
 * $user->fresh() is null regardless of whether verification worked.
 */
function verificationUrlFor(User $user, ?string $hash = null): string
{
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => $hash ?? sha1($user->getEmailForVerification()),
    ]);

    return (string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
}

function hasVerified(User $user): bool
{
    return asAccount($user, fn (): bool => $user->fresh()?->hasVerifiedEmail() === true);
}

it('verifies an email from the signed link', function (): void {
    Event::fake([Verified::class]);

    $user = createUser(['email_verified_at' => null]);

    $this->getJson(verificationUrlFor($user))
        ->assertOk()
        ->assertJsonPath('message', __('auth.email_verified'));

    expect(hasVerified($user))->toBeTrue();
    Event::assertDispatched(Verified::class);
});

it('locks the verified-only routes until the address is verified', function (): void {
    // Taxation sits behind the `verified` middleware, so an account that
    // cannot verify cannot reach it at all.
    $user = createUser(['email_verified_at' => null, 'country' => 'SK']);

    $this->actingAs($user)->getJson('/api/v1/contributions/summary')->assertForbidden();
});

it('unlocks the verified-only routes once verified', function (): void {
    $user = createUser(['email_verified_at' => null, 'country' => 'SK']);

    // Deliberately before any actingAs(): clicking the link is a plain
    // browser request with no bearer token, so nothing binds the connection
    // for it. Authenticating first would bind the account through
    // BindAuthenticatedTenant and hide the very thing this covers.
    $this->getJson(verificationUrlFor($user))->assertOk();

    $verified = asAccount($user, fn (): ?User => $user->fresh());

    $this->actingAs($verified)->getJson('/api/v1/contributions/summary')->assertOk();
});

it('rejects a link whose hash does not match the address', function (): void {
    $user = createUser(['email_verified_at' => null]);

    $this->getJson(verificationUrlFor($user, sha1('someone-else@example.com')))
        ->assertForbidden();

    expect(hasVerified($user))->toBeFalse();
});

it('rejects an unsigned link', function (): void {
    $user = createUser(['email_verified_at' => null]);

    $this->getJson("/api/v1/auth/email/verify/{$user->id}/".sha1($user->getEmailForVerification()))
        ->assertForbidden();

    expect(hasVerified($user))->toBeFalse();
});

it('stays idempotent on a second visit', function (): void {
    $user = createUser(['email_verified_at' => null]);
    $url = verificationUrlFor($user);

    $this->getJson($url)->assertOk();
    $this->getJson($url)->assertOk();

    expect(hasVerified($user))->toBeTrue();
});
