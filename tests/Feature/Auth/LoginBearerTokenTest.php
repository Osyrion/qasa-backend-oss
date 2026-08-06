<?php

declare(strict_types=1);

/**
 * LoginAction used to query the core Auth\Domain\Models\User class by name
 * instead of the edition-bound provider model. The row it found was real,
 * but in the SaaS edition it was the wrong PHP class — and Sanctum's own
 * hasValidProvider() check rejects a token whose tokenable resolves to a
 * class other than config('auth.providers.users.model'). The bug never
 * showed up in a Sanctum::actingAs()-based test: that path never calls
 * findToken() at all. Only a genuine second HTTP request, bearer-
 * authenticated with the token /login actually returned, exercises it.
 */
it('lets the token /login returns authenticate a second, separate request', function (): void {
    $user = createUser();

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $token = $login->json('token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email);
});
