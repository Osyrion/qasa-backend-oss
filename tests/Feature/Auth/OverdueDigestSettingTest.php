<?php

declare(strict_types=1);

it('defaults the overdue digest to enabled', function (): void {
    $user = createUser();

    expect($user->overdue_digest_enabled)->toBeTrue();

    $this->actingAs($user)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('overdue_digest_enabled', true);
});

it('disables the overdue digest via the profile endpoint', function (): void {
    $user = createUser();

    $this->actingAs($user)
        ->putJson('/api/v1/auth/profile', ['overdue_digest_enabled' => false])
        ->assertOk()
        ->assertJsonPath('overdue_digest_enabled', false);

    expect($user->refresh()->overdue_digest_enabled)->toBeFalse();
});

it('re-enables the overdue digest after disabling it', function (): void {
    $user = createUser(['overdue_digest_enabled' => false]);

    $this->actingAs($user)
        ->putJson('/api/v1/auth/profile', ['overdue_digest_enabled' => true])
        ->assertOk()
        ->assertJsonPath('overdue_digest_enabled', true);

    expect($user->refresh()->overdue_digest_enabled)->toBeTrue();
});
