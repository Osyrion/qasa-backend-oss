<?php

declare(strict_types=1);

use App\Modules\Auth\Application\Actions\LoginWithGoogleAction;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Mockery\MockInterface;

it('returns 404 for registration when the feature is disabled', function (): void {
    config()->set('qasa.features.registration', false);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ján',
        'surname' => 'Novák',
        'email' => 'jan@example.com',
        'password' => 'super-secret-1',
    ])->assertNotFound();
});

it('registers a user when the feature is enabled', function (): void {
    config()->set('qasa.features.registration', true);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ján',
        'surname' => 'Novák',
        'email' => 'jan@example.com',
        'password' => 'super-secret-1',
    ])->assertCreated();
});

it('rejects an unknown google account when registration is disabled', function (): void {
    config()->set('qasa.features.registration', false);

    /** @var SocialiteUser&MockInterface $googleUser */
    $googleUser = Mockery::mock(SocialiteUser::class);
    $googleUser->shouldReceive('getEmail')->andReturn('unknown@example.com');

    app(LoginWithGoogleAction::class)->execute($googleUser);
})->throws(ValidationException::class);

it('still logs in an existing user via google when registration is disabled', function (): void {
    config()->set('qasa.features.registration', false);

    $user = createUser(['email' => 'existing@example.com']);

    /** @var SocialiteUser&MockInterface $googleUser */
    $googleUser = Mockery::mock(SocialiteUser::class);
    $googleUser->shouldReceive('getEmail')->andReturn('existing@example.com');
    $googleUser->shouldReceive('getId')->andReturn('google-123');
    $googleUser->shouldReceive('getAvatar')->andReturn(null);

    $token = app(LoginWithGoogleAction::class)->execute($googleUser);

    $user->refresh();

    expect($token)->toBeString()->not->toBeEmpty()
        ->and($user->google_id)->toBe('google-123');
});

it('creates a user via the qasa:user command', function (): void {
    $this->artisan('qasa:user', [
        '--name' => 'Cli',
        '--surname' => 'User',
        '--email' => 'cli@example.com',
        '--password' => 'super-secret-1',
    ])->assertSuccessful();

    $user = userModel()::query()->where('email', 'cli@example.com')->firstOrFail();

    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->hasPassword())->toBeTrue()
        ->and($user->roleName())->toBe('owner');
});

it('rejects a duplicate e-mail in the qasa:user command', function (): void {
    createUser(['email' => 'taken@example.com']);

    $this->artisan('qasa:user', [
        '--name' => 'Cli',
        '--surname' => 'User',
        '--email' => 'taken@example.com',
        '--password' => 'super-secret-1',
    ])->assertFailed();
});
