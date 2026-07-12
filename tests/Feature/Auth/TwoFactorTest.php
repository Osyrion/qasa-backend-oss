<?php

declare(strict_types=1);

use App\Modules\Auth\Application\Actions\LoginWithGoogleAction;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Mockery\MockInterface;
use PragmaRX\Google2FA\Google2FA;

function currentTotp(string $secret): string
{
    return (new Google2FA)->getCurrentOtp($secret);
}

it('walks the full setup and login-challenge happy path', function (): void {
    $user = createUser(['email' => 'totp@example.com']);

    $enable = $this->actingAs($user)->postJson('/api/v1/auth/2fa/enable');
    $enable->assertOk()->assertJsonStructure(['secret', 'otpauth_uri', 'qr_svg']);

    $secret = $enable->json('secret');

    $confirm = $this->actingAs($user)->postJson('/api/v1/auth/2fa/confirm', [
        'code' => currentTotp($secret),
    ]);
    $confirm->assertOk();
    expect($confirm->json('recovery_codes'))->toHaveCount(8);
    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue();

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'totp@example.com',
        'password' => 'password',
    ]);
    $login->assertOk()
        ->assertJsonPath('two_factor_required', true)
        ->assertJsonMissingPath('token');

    $challengeToken = $login->json('challenge_token');

    $this->postJson('/api/v1/auth/2fa/verify', [
        'challenge_token' => $challengeToken,
        'code' => currentTotp($secret),
    ])->assertOk()->assertJsonStructure(['token', 'user']);
});

it('does not require a challenge when 2FA was enabled but never confirmed', function (): void {
    $user = createUser(['email' => 'unconfirmed@example.com']);

    $this->actingAs($user)->postJson('/api/v1/auth/2fa/enable')->assertOk();

    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'unconfirmed@example.com',
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('two_factor_required', false)
        ->assertJsonStructure(['token', 'user']);
});

it('rejects an unknown challenge token', function (): void {
    $this->postJson('/api/v1/auth/2fa/verify', [
        'challenge_token' => 'does-not-exist',
        'code' => '000000',
    ])->assertUnprocessable();
});

it('rejects an expired challenge token', function (): void {
    $secret = (new Google2FA)->generateSecretKey();
    $user = userModel()::factory()->withTwoFactor($secret)->create(['email' => 'expired@example.com']);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $challengeToken = $login->json('challenge_token');

    $this->travel(6)->minutes();

    $this->postJson('/api/v1/auth/2fa/verify', [
        'challenge_token' => $challengeToken,
        'code' => currentTotp($secret),
    ])->assertUnprocessable();
});

it('consumes the challenge token — it cannot be reused', function (): void {
    $secret = (new Google2FA)->generateSecretKey();
    $user = userModel()::factory()->withTwoFactor($secret)->create(['email' => 'reuse@example.com']);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $challengeToken = $login->json('challenge_token');

    $this->postJson('/api/v1/auth/2fa/verify', [
        'challenge_token' => $challengeToken,
        'code' => currentTotp($secret),
    ])->assertOk();

    $this->postJson('/api/v1/auth/2fa/verify', [
        'challenge_token' => $challengeToken,
        'code' => currentTotp($secret),
    ])->assertUnprocessable();
});

it('logs in with a recovery code and the same code cannot be reused', function (): void {
    $secret = (new Google2FA)->generateSecretKey();
    $user = userModel()::factory()
        ->withTwoFactor($secret, ['alpha-code', 'beta-code'])
        ->create(['email' => 'recovery@example.com']);

    $firstLogin = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->postJson('/api/v1/auth/2fa/verify', [
        'challenge_token' => $firstLogin->json('challenge_token'),
        'code' => 'alpha-code',
    ])->assertOk();

    $secondLogin = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->postJson('/api/v1/auth/2fa/verify', [
        'challenge_token' => $secondLogin->json('challenge_token'),
        'code' => 'alpha-code',
    ])->assertUnprocessable();

    // The other recovery code is untouched.
    $thirdLogin = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->postJson('/api/v1/auth/2fa/verify', [
        'challenge_token' => $thirdLogin->json('challenge_token'),
        'code' => 'beta-code',
    ])->assertOk();
});

it('rejects an invalid code at the challenge step', function (): void {
    $secret = (new Google2FA)->generateSecretKey();
    $user = userModel()::factory()->withTwoFactor($secret)->create(['email' => 'badcode@example.com']);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->postJson('/api/v1/auth/2fa/verify', [
        'challenge_token' => $login->json('challenge_token'),
        'code' => '000000',
    ])->assertUnprocessable();
});

it('disables 2FA with the correct password and code', function (): void {
    $secret = (new Google2FA)->generateSecretKey();
    $user = userModel()::factory()->withTwoFactor($secret)->create();

    $this->actingAs($user)->deleteJson('/api/v1/auth/2fa', [
        'password' => 'password',
        'code' => currentTotp($secret),
    ])->assertNoContent();

    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->two_factor_secret)->toBeNull();
});

it('refuses to disable 2FA with the wrong password', function (): void {
    $secret = (new Google2FA)->generateSecretKey();
    $user = userModel()::factory()->withTwoFactor($secret)->create();

    $this->actingAs($user)->deleteJson('/api/v1/auth/2fa', [
        'password' => 'wrong-password',
        'code' => currentTotp($secret),
    ])->assertUnprocessable();

    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('refuses to disable 2FA with the wrong code', function (): void {
    $secret = (new Google2FA)->generateSecretKey();
    $user = userModel()::factory()->withTwoFactor($secret)->create();

    $this->actingAs($user)->deleteJson('/api/v1/auth/2fa', [
        'password' => 'password',
        'code' => '000000',
    ])->assertUnprocessable();

    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('regenerates recovery codes and invalidates the previous set', function (): void {
    $secret = (new Google2FA)->generateSecretKey();
    $user = userModel()::factory()
        ->withTwoFactor($secret, ['old-code-1', 'old-code-2'])
        ->create(['email' => 'regen@example.com']);

    $this->actingAs($user)->postJson('/api/v1/auth/2fa/recovery-codes', [
        'code' => currentTotp($secret),
    ])->assertOk()->assertJsonStructure(['recovery_codes']);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->postJson('/api/v1/auth/2fa/verify', [
        'challenge_token' => $login->json('challenge_token'),
        'code' => 'old-code-1',
    ])->assertUnprocessable();
});

it('requires a valid TOTP code to confirm setup', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/auth/2fa/enable')->assertOk();

    $this->actingAs($user)->postJson('/api/v1/auth/2fa/confirm', [
        'code' => '000000',
    ])->assertUnprocessable();

    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('refuses to enable 2FA again once already confirmed', function (): void {
    $secret = (new Google2FA)->generateSecretKey();
    $user = userModel()::factory()->withTwoFactor($secret)->create();

    $this->actingAs($user)->postJson('/api/v1/auth/2fa/enable')->assertUnprocessable();
});

it('challenges a 2FA-enabled account logging in via Google', function (): void {
    $secret = (new Google2FA)->generateSecretKey();
    $user = userModel()::factory()->withTwoFactor($secret)->create(['email' => 'google-2fa@example.com']);

    /** @var SocialiteUser&MockInterface $googleUser */
    $googleUser = Mockery::mock(SocialiteUser::class);
    $googleUser->shouldReceive('getEmail')->andReturn($user->email);
    $googleUser->shouldReceive('getId')->andReturn('google-456');
    $googleUser->shouldReceive('getAvatar')->andReturn(null);

    $result = app(LoginWithGoogleAction::class)->execute($googleUser);

    expect($result->twoFactorRequired)->toBeTrue()
        ->and($result->challengeToken)->toBeString();

    $this->postJson('/api/v1/auth/2fa/verify', [
        'challenge_token' => $result->challengeToken,
        'code' => currentTotp($secret),
    ])->assertOk()->assertJsonStructure(['token', 'user']);
});

it('throttles the verify endpoint', function (): void {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge_token' => 'nonexistent',
            'code' => '000000',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/2fa/verify', [
        'challenge_token' => 'nonexistent',
        'code' => '000000',
    ])->assertStatus(429);
});
