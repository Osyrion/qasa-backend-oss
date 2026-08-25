<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/**
 * The reset flow reads `users` on two unauthenticated requests, and `users`
 * carries a tenant policy since phase 7 (docs/plans/POSTGRES_RLS_PLAN.md).
 * Laravel's own EloquentUserProvider does that read, so there is no query in
 * this codebase to scope — the account has to be bound before the broker is
 * called at all, the same SECURITY DEFINER pattern LoginAction uses.
 *
 * Without that binding the broker returns INVALID_USER, and the deliberately
 * generic "if an account exists" response makes the failure invisible: 200,
 * no mail, no log. That is what these cover.
 *
 * asAccount() around every post-request read is not ceremony: an
 * unauthenticated request leaves the connection cleared (BindTenantContext),
 * so a bare $user->fresh() afterwards reads nothing and an assertion like
 * "no tokens left" passes without the fix ever running.
 */
it('sends a reset link for a known address', function (): void {
    Notification::fake();

    $user = createUser();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
        ->assertOk();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('stashes the mobile platform for the reset e-mail closure to read, keyed by e-mail', function (): void {
    // Faked so the notification is never actually rendered. Unfaked, the
    // real send goes on to call toMail() within the same request, and
    // createUrlUsing()'s closure (registered in AuthServiceProvider)
    // Cache::pull()s this exact key as part of building the URL — the
    // assertion below would then see it already gone, for the right
    // behavior but the wrong reason.
    Notification::fake();

    $user = createUser();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email, 'platform' => 'mobile'])
        ->assertOk();

    expect(Cache::get('password_reset_platform:'.$user->email))->toBe('mobile');
});

it('does not stash a platform for a plain web request', function (): void {
    Notification::fake();

    $user = createUser();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
        ->assertOk();

    expect(Cache::get('password_reset_platform:'.$user->email))->toBeNull();
});

it('builds the app deep link, not the SPA URL, once the mobile platform is stashed', function (): void {
    $user = createUser();
    Cache::put('password_reset_platform:'.$user->email, 'mobile', now()->addMinutes(10));

    $mail = (new ResetPassword('a-token'))->toMail($user);

    expect($mail->actionUrl)->toBe('flok://reset-password?token=a-token&email='.urlencode($user->email));
    // One-shot, same as the OAuth state: a second render must not still see it.
    expect(Cache::get('password_reset_platform:'.$user->email))->toBeNull();
});

it('builds the SPA URL when no platform was stashed', function (): void {
    $user = createUser();

    $mail = (new ResetPassword('a-token'))->toMail($user);
    $frontendUrl = config('app.frontend_url');
    assert(is_string($frontendUrl) && $frontendUrl !== '');

    expect($mail->actionUrl)->toStartWith($frontendUrl)
        ->and($mail->actionUrl)->toContain('/reset-password?token=a-token');
});

it('resets the password with a valid token', function (): void {
    $user = createUser();
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Str0ngPassword123',
    ])->assertOk();

    $password = asAccount($user, fn (): ?string => $user->fresh()?->password);

    expect(Hash::check('Str0ngPassword123', (string) $password))->toBeTrue();
});

it('revokes every api token when the password is reset', function (): void {
    $user = createUser();
    $user->createToken('phone');
    $token = Password::broker()->createToken($user);

    expect(asAccount($user, fn (): int => $user->tokens()->count()))->toBe(1);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Str0ngPassword123',
    ])->assertOk();

    expect(asAccount($user, fn (): int => $user->tokens()->count()))->toBe(0);
});

it('keeps the response generic for an unknown address', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])
        ->assertOk()
        ->assertJsonPath('message', __('auth.password_reset_link_sent'));

    Notification::assertNothingSent();
});

it('rejects a reset token issued for another account', function (): void {
    $victim = createUser();
    $attacker = createUser();

    $token = asAccount($attacker, fn (): string => Password::broker()->createToken($attacker));

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $victim->email,
        'password' => 'Str0ngPassword123',
    ])->assertUnprocessable();

    $password = asAccount($victim, fn (): ?string => $victim->fresh()?->password);

    expect(Hash::check('Str0ngPassword123', (string) $password))->toBeFalse();
});
