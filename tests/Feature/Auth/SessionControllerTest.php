<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;

/**
 * Every request here goes through a real Authorization header, never
 * actingAs(): Sanctum's own guard (Laravel\Sanctum\Guard::__invoke())
 * unconditionally overwrites currentAccessToken() with a fresh
 * TransientToken whenever it resolves the user via actingAs()'s guard
 * instead of a real bearer token — so actingAs() can never exercise
 * "is this the session the request used", the exact thing these tests
 * are about. createSessionToken() (not the /auth/login endpoint) is the
 * token source: LoginAction and its siblings query the core
 * Auth\Domain\Models\User class by name rather than the edition's bound
 * provider model, so a token they issue ends up tagged with a
 * tokenable_type Sanctum's own provider check then rejects in the SaaS
 * edition — a pre-existing bug outside this feature's scope, filed
 * separately. createSessionToken() called directly on the edition's real
 * user instance (exactly what LoginAction *should* be doing) sidesteps it.
 *
 * @return array{0: string, 1: int|string} plaintext token, token id
 */
function sessionBearer(User $user, string $deviceName): array
{
    $token = $user->createSessionToken($deviceName);

    return [$token->plainTextToken, $token->accessToken->id];
}

it('lists only session-type tokens, never API integration tokens', function (): void {
    $user = createUser();
    [$plain] = sessionBearer($user, 'current-device');

    $apiToken = $user->createToken('zapier', ['invoices.view']);
    $apiToken->accessToken->forceFill(['type' => 'api'])->save();

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->getJson('/api/v1/auth/sessions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'current-device');
});

it('flags the token the request is authenticated with as the current session', function (): void {
    $user = createUser();
    [$plain] = sessionBearer($user, 'phone');

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->getJson('/api/v1/auth/sessions')
        ->assertOk()
        ->assertJsonPath('data.0.is_current', true);
});

it('revokes another session but refuses to revoke the current one', function (): void {
    $user = createUser();
    [$currentPlain, $currentId] = sessionBearer($user, 'phone');
    [, $otherId] = sessionBearer($user, 'laptop');

    $this->withHeader('Authorization', "Bearer {$currentPlain}")
        ->deleteJson("/api/v1/auth/sessions/{$otherId}")
        ->assertNoContent();

    $this->withHeader('Authorization', "Bearer {$currentPlain}")
        ->deleteJson("/api/v1/auth/sessions/{$currentId}")
        ->assertUnprocessable();

    asAccount($user, function () use ($user, $otherId, $currentId): void {
        expect($user->tokens()->whereKey($otherId)->exists())->toBeFalse()
            ->and($user->tokens()->whereKey($currentId)->exists())->toBeTrue();
    });
});

it('cannot revoke another account session by id', function (): void {
    $owner = createUser();
    $ownerTokenId = asAccount($owner, fn () => $owner->createSessionToken('owner-device'))->accessToken->id;

    $stranger = createUser();
    [$strangerPlain] = sessionBearer($stranger, 'stranger-device');

    $this->withHeader('Authorization', "Bearer {$strangerPlain}")
        ->deleteJson("/api/v1/auth/sessions/{$ownerTokenId}")
        ->assertNotFound();

    asAccount($owner, fn () => expect($owner->tokens()->whereKey($ownerTokenId)->exists())->toBeTrue());
});

it('logs out every other session but keeps the current one', function (): void {
    $user = createUser();
    [$currentPlain, $currentId] = sessionBearer($user, 'phone');
    sessionBearer($user, 'laptop');
    sessionBearer($user, 'tablet');

    $this->withHeader('Authorization', "Bearer {$currentPlain}")
        ->deleteJson('/api/v1/auth/sessions')
        ->assertOk()
        ->assertJsonPath('revoked', 2);

    $remaining = asAccount($user, fn () => $user->tokens()->where('type', 'session')->pluck('id'));

    expect($remaining->all())->toBe([$currentId]);
});

it('does not list an API integration token among sessions even when created directly on the model', function (): void {
    $user = createUser();
    [$plain] = sessionBearer($user, 'current-device');

    $user->createToken('untyped-legacy-token');

    $names = $this->withHeader('Authorization', "Bearer {$plain}")
        ->getJson('/api/v1/auth/sessions')
        ->json('data.*.name');

    expect($names)->toBe(['current-device']);
});

it('registers a push token on the current session', function (): void {
    $user = createUser();
    [$plain, $tokenId] = sessionBearer($user, 'phone');

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->putJson('/api/v1/auth/push-token', ['push_token' => 'ExponentPushToken[abc]', 'push_platform' => 'ios'])
        ->assertNoContent();

    asAccount($user, function () use ($user, $tokenId): void {
        $token = $user->tokens()->whereKey($tokenId)->firstOrFail();
        expect($token->push_token)->toBe('ExponentPushToken[abc]')
            ->and($token->push_platform)->toBe('ios');
    });

    $sessions = $this->withHeader('Authorization', "Bearer {$plain}")
        ->getJson('/api/v1/auth/sessions')
        ->assertOk();

    expect($sessions->json('data.0.has_push_token'))->toBeTrue();
});

it('clears a push token by sending null', function (): void {
    $user = createUser();
    [$plain, $tokenId] = sessionBearer($user, 'phone');

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->putJson('/api/v1/auth/push-token', ['push_token' => 'ExponentPushToken[abc]', 'push_platform' => 'ios'])
        ->assertNoContent();

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->putJson('/api/v1/auth/push-token', ['push_token' => null, 'push_platform' => null])
        ->assertNoContent();

    asAccount($user, function () use ($user, $tokenId): void {
        $token = $user->tokens()->whereKey($tokenId)->firstOrFail();
        expect($token->push_token)->toBeNull();
    });
});

it('rejects a push token registration from an API integration token', function (): void {
    $user = createUser();
    subscribeToPaidPlan($user);
    $apiToken = $user->createToken('zapier', ['invoices.view']);
    $apiToken->accessToken->forceFill(['type' => 'api'])->save();

    $this->withHeader('Authorization', "Bearer {$apiToken->plainTextToken}")
        ->putJson('/api/v1/auth/push-token', ['push_token' => 'ExponentPushToken[abc]', 'push_platform' => 'ios'])
        ->assertUnprocessable();
});

it('moves a push token off the session that held it before', function (): void {
    $user = createUser();
    [$oldPlain, $oldTokenId] = sessionBearer($user, 'phone-before-reinstall');
    [$newPlain, $newTokenId] = sessionBearer($user, 'phone-after-reinstall');

    foreach ([$oldPlain, $newPlain] as $plain) {
        // Two bearer tokens, two requests, one test process: the guard
        // memoizes the user (and with it currentAccessToken()) the first
        // time it resolves one, so without this the second request would
        // still be running as the first session — the exact distinction
        // this test is about.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$plain}")
            ->putJson('/api/v1/auth/push-token', ['push_token' => 'ExponentPushToken[same-device]', 'push_platform' => 'android'])
            ->assertNoContent();
    }

    asAccount($user, function () use ($user, $oldTokenId, $newTokenId): void {
        expect($user->tokens()->whereKey($oldTokenId)->firstOrFail()->push_token)->toBeNull()
            ->and($user->tokens()->whereKey($oldTokenId)->firstOrFail()->push_platform)->toBeNull()
            ->and($user->tokens()->whereKey($newTokenId)->firstOrFail()->push_token)->toBe('ExponentPushToken[same-device]');
    });
});

it('requires push_platform when a push_token is set', function (): void {
    $user = createUser();
    [$plain] = sessionBearer($user, 'phone');

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->putJson('/api/v1/auth/push-token', ['push_token' => 'ExponentPushToken[abc]'])
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('push_platform');
});
