<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Support\Facades\Hash;

it('deletes an account with the correct password', function (): void {
    $user = createUser(['password' => Hash::make('correct-password')]);
    $token = $user->createToken('device')->plainTextToken;

    $this->actingAs($user)
        ->deleteJson('/api/v1/profile', ['password' => 'correct-password'])
        ->assertNoContent();

    expect(User::withTrashed()->findOrFail($user->id)->trashed())->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
});

it('rejects deletion with the wrong password', function (): void {
    $user = createUser(['password' => Hash::make('correct-password')]);

    $this->actingAs($user)
        ->deleteJson('/api/v1/profile', ['password' => 'wrong-password'])
        ->assertUnprocessable();

    expect(User::findOrFail($user->id)->trashed())->toBeFalse();
});

it('deletes a Google-only account with the DELETE confirmation string', function (): void {
    $user = createUser(['password' => null, 'google_id' => 'google-123']);

    $this->actingAs($user)
        ->deleteJson('/api/v1/profile', ['confirmation' => 'DELETE'])
        ->assertNoContent();

    expect(User::withTrashed()->findOrFail($user->id)->trashed())->toBeTrue();
});

it('rejects a Google-only account deletion without the confirmation string', function (): void {
    $user = createUser(['password' => null, 'google_id' => 'google-123']);

    $this->actingAs($user)
        ->deleteJson('/api/v1/profile', [])
        ->assertUnprocessable();

    expect(User::findOrFail($user->id)->trashed())->toBeFalse();
});

it('prevents a soft-deleted user from logging in', function (): void {
    $user = createUser(['password' => Hash::make('correct-password')]);

    $this->actingAs($user)->deleteJson('/api/v1/profile', ['password' => 'correct-password'])->assertNoContent();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-password',
    ])->assertUnprocessable();
});
