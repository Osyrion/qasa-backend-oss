<?php

declare(strict_types=1);

it('updates the overdue reminder threshold via the profile endpoint', function (): void {
    $user = createUser();

    $this->actingAs($user)
        ->putJson('/api/v1/auth/profile', ['overdue_reminder_days' => 30])
        ->assertOk()
        ->assertJsonPath('overdue_reminder_days', 30);

    expect($user->refresh()->overdue_reminder_days)->toBe(30);
});

it('defaults the overdue reminder threshold to 14 days', function (): void {
    $user = createUser();

    expect($user->overdue_reminder_days)->toBe(14);

    // A profile update without the field leaves the default untouched.
    $this->actingAs($user)
        ->putJson('/api/v1/auth/profile', ['name' => 'Nové Meno'])
        ->assertOk()
        ->assertJsonPath('overdue_reminder_days', 14);
});

it('rejects an out-of-range overdue reminder threshold', function (int $days): void {
    $user = createUser();

    $this->actingAs($user)
        ->putJson('/api/v1/auth/profile', ['overdue_reminder_days' => $days])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('overdue_reminder_days');
})->with([[0], [366]]);
