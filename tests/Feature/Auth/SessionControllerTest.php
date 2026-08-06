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
