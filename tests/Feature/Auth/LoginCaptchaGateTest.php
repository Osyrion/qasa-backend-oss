<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Contracts\CaptchaVerifierInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Second layer over the (e-mail + IP) backoff.
 *
 * That one deliberately cannot see an attacker who rotates addresses: every
 * new one is a fresh bucket, which is the price of never being able to lock
 * the account owner out. This layer counts failures against the *account*
 * regardless of where they came from, and once there have been enough it
 * escalates friction rather than refusing — the next attempt has to carry a
 * solved captcha. An attacker pays a captcha per guess, which is what kills
 * distributed guessing; the owner pays one captcha, once, and is never shut
 * out of their own account.
 */
beforeEach(function (): void {
    Cache::flush();

    // Stands in for a live Turnstile: nothing but a solved token passes.
    app()->instance(CaptchaVerifierInterface::class, new class implements CaptchaVerifierInterface
    {
        public function verify(?string $token, ?string $remoteIp): bool
        {
            return $token === 'solved';
        }
    });
});

/**
 * Ten failures spread over two addresses — five each, so the per-pair
 * backoff never fires and the account counter is the only thing watching.
 */
function hammerAccountFromRotatingIps(string $email, int $perIp = 5): void
{
    foreach (['203.0.113.9', '198.51.100.7'] as $ip) {
        for ($i = 0; $i < $perIp; $i++) {
            test()->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/v1/auth/login', [
                    'email' => $email,
                    'password' => 'wrong-password',
                ]);
        }
    }
}

it('demands a captcha once the account has been hammered from many addresses', function (): void {
    $user = createUser();

    hammerAccountFromRotatingIps($user->email);

    // A third address the backoff has never seen — and the right password,
    // to show the gate closes ahead of the credential check rather than
    // because the guess was wrong.
    test()->withServerVariables(['REMOTE_ADDR' => '192.0.2.33'])
        ->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
        ->assertStatus(422)
        ->assertJsonPath('captcha_required', true);
});

it('lets the owner straight in once they solve it', function (): void {
    $user = createUser();

    hammerAccountFromRotatingIps($user->email);

    test()->withServerVariables(['REMOTE_ADDR' => '192.0.2.33'])
        ->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'turnstile_token' => 'solved',
        ])->assertOk();
});

it('asks for nothing until the account has actually been hammered', function (): void {
    $user = createUser();

    // Half the budget spent, from one address.
    hammerAccountFromRotatingIps($user->email, perIp: 2);

    test()->withServerVariables(['REMOTE_ADDR' => '192.0.2.33'])
        ->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
});

it('forgets the account counter once someone logs in successfully', function (): void {
    $user = createUser();

    hammerAccountFromRotatingIps($user->email);

    test()->withServerVariables(['REMOTE_ADDR' => '192.0.2.33'])
        ->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'turnstile_token' => 'solved',
        ])->assertOk();

    // The slate is clean, so the next visit needs no captcha at all.
    test()->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])
        ->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
});

it('keeps out of the way entirely on a deployment without Turnstile', function (): void {
    // The real verifier this time, switched off the way an install that
    // never configured Turnstile has it. The gate has to be a no-op there:
    // demanding a captcha nobody can render would lock the account for real.
    app()->forgetInstance(CaptchaVerifierInterface::class);
    config()->set('services.turnstile.enabled', false);

    $user = createUser();

    hammerAccountFromRotatingIps($user->email);

    test()->withServerVariables(['REMOTE_ADDR' => '192.0.2.33'])
        ->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
});
