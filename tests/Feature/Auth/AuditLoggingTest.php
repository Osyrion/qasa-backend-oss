<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Models\ActivityLog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

// Login, 2FA-verify and password-reset all happen unauthenticated (no
// actingAs), so — same as the existing user.registered activity test —
// TenantContext is not auto-restored once the HTTP call terminates and the
// row has to be read back with asAccount(), scoped by the target user's own
// account rather than whatever the test's ambient binding is.
it('records an activity entry on successful login', function (): void {
    $user = createUser(['email' => 'login-ok@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $entry = asAccount($user, fn () => ActivityLog::withoutGlobalScope('user')
        ->where('user_id', $user->id)
        ->where('event', 'auth.login_succeeded')
        ->firstOrFail());

    expect($entry->subject_id)->toBe($user->id);
});

it('records an activity entry on a failed login with the wrong password', function (): void {
    $user = createUser(['email' => 'login-fail@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertUnprocessable();

    $entry = asAccount($user, fn () => ActivityLog::withoutGlobalScope('user')
        ->where('user_id', $user->id)
        ->where('event', 'auth.login_failed')
        ->firstOrFail());

    expect($entry->changes)->toBe(['reason' => 'invalid_password']);
});

it('logs a failed login for an unknown email to the security channel, not the activity log', function (): void {
    // Mocking the Log facade here fights the framework more than it tests
    // the feature (LogManager's passthrough methods need a real $app
    // binding a Mockery partial mock doesn't have) — asserting against the
    // real channel file is simpler and exercises the actual config.
    $securityLogPath = storage_path('logs/security-'.now()->toDateString().'.log');
    File::ensureDirectoryExists(dirname($securityLogPath));
    File::put($securityLogPath, '');

    $email = 'nobody-here-'.Str::random(8).'@example.com';

    $this->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => 'whatever',
    ])->assertUnprocessable();

    expect(ActivityLog::withoutGlobalScope('user')->where('event', 'auth.login_failed')->exists())->toBeFalse();

    $logged = File::get($securityLogPath);
    expect($logged)->toContain('login attempt: unknown email')->toContain($email);
});

it('records an activity entry on logout, and tolerates a session-authenticated (non-bearer) token', function (): void {
    $user = createUser(['email' => 'logout@example.com']);

    // actingAs() authenticates without a real Sanctum token —
    // currentAccessToken() is a TransientToken here, which has no delete()
    // method at all. Regression test for AuthController::logout() crashing
    // with a 500 on exactly this path (stateful/session-authenticated
    // requests hit the same TransientToken case in production).
    $this->actingAs($user)->postJson('/api/v1/auth/logout')->assertOk();

    $entry = ActivityLog::where('user_id', $user->id)->where('event', 'auth.logout')->firstOrFail();

    expect($entry->subject_id)->toBe($user->id);
});

it('records an activity entry on login via a completed 2FA challenge', function (): void {
    $secret = (new Google2FA)->generateSecretKey();
    $user = createUser(['email' => 'totp-audit@example.com'], fn ($factory) => $factory->withTwoFactor($secret));

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->postJson('/api/v1/auth/2fa/verify', [
        'challenge_token' => $login->json('challenge_token'),
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertOk();

    asAccount($user, fn () => ActivityLog::withoutGlobalScope('user')
        ->where('user_id', $user->id)
        ->where('event', 'auth.login_succeeded')
        ->firstOrFail());
});

it('records activity entries across the 2FA enable/confirm/disable/regenerate lifecycle', function (): void {
    $user = createUser(['email' => 'twofactor-audit@example.com']);

    $enable = $this->actingAs($user)->postJson('/api/v1/auth/2fa/enable');
    $enable->assertOk();
    ActivityLog::where('user_id', $user->id)->where('event', 'auth.two_factor_enabled')->firstOrFail();

    $secret = $enable->json('secret');
    $this->actingAs($user)->postJson('/api/v1/auth/2fa/confirm', [
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertOk();
    ActivityLog::where('user_id', $user->id)->where('event', 'auth.two_factor_confirmed')->firstOrFail();

    $this->actingAs($user)->postJson('/api/v1/auth/2fa/recovery-codes', [
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertOk();
    ActivityLog::where('user_id', $user->id)->where('event', 'auth.recovery_codes_regenerated')->firstOrFail();

    $this->actingAs($user)->deleteJson('/api/v1/auth/2fa', [
        'password' => 'password',
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertNoContent();
    ActivityLog::where('user_id', $user->id)->where('event', 'auth.two_factor_disabled')->firstOrFail();
});

it('records an activity entry on password reset', function (): void {
    $user = createUser(['email' => 'reset-audit@example.com']);

    $token = app('auth.password.broker')->createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password-1',
    ])->assertOk();

    $entry = asAccount($user, fn () => ActivityLog::withoutGlobalScope('user')
        ->where('user_id', $user->id)
        ->where('event', 'auth.password_reset')
        ->firstOrFail());

    expect($entry->subject_id)->toBe($user->id);
});

it('records an activity entry when the account is deleted', function (): void {
    $user = createUser(['email' => 'delete-audit@example.com']);

    $this->actingAs($user)->deleteJson('/api/v1/profile', [
        'password' => 'password',
    ])->assertNoContent();

    $entry = ActivityLog::where('user_id', $user->id)->where('event', 'account.deleted')->firstOrFail();

    expect($entry->subject_id)->toBe($user->id);
});
