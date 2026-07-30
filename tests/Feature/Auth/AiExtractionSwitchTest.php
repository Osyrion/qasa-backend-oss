<?php

declare(strict_types=1);

it('defaults ai_extraction_enabled to false', function (): void {
    $user = createUser();

    expect($user->ai_extraction_enabled)->toBeFalse();

    $this->actingAs($user)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.ai_extraction_enabled', false);
});

it('enables ai extraction via the profile endpoint', function (): void {
    $user = createUser();

    $this->actingAs($user)
        ->putJson('/api/v1/auth/profile', ['ai_extraction_enabled' => true])
        ->assertOk()
        ->assertJsonPath('data.ai_extraction_enabled', true);

    expect($user->refresh()->ai_extraction_enabled)->toBeTrue();
});

it('disables ai extraction again via the profile endpoint', function (): void {
    $user = createUser(['ai_extraction_enabled' => true]);

    $this->actingAs($user)
        ->putJson('/api/v1/auth/profile', ['ai_extraction_enabled' => false])
        ->assertOk()
        ->assertJsonPath('data.ai_extraction_enabled', false);

    expect($user->refresh()->ai_extraction_enabled)->toBeFalse();
});

it('leaves ai_extraction_enabled untouched when the field is not sent at all', function (): void {
    $user = createUser(['ai_extraction_enabled' => true]);

    $this->actingAs($user)
        ->putJson('/api/v1/auth/profile', ['name' => 'Nové meno'])
        ->assertOk();

    expect($user->refresh()->ai_extraction_enabled)->toBeTrue();
});
