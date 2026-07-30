<?php

declare(strict_types=1);

use App\Modules\Auth\Application\Actions\LoginWithGoogleAction;
use App\Modules\Auth\Domain\Models\User;
use Illuminate\Validation\ValidationException;

it('rejects a new Google account whose e-mail is not verified', function (): void {
    config()->set('qasa.features.registration', true);

    $googleUser = mockGoogleUser('unverified@example.com', 'google-1', emailVerified: false, name: 'Ján Novák');

    app(LoginWithGoogleAction::class)->execute($googleUser);
})->throws(ValidationException::class);

it('rejects linking an existing account via an unverified Google e-mail', function (): void {
    $user = createUser(['email' => 'victim@example.com', 'google_id' => null]);

    $googleUser = mockGoogleUser('victim@example.com', 'attacker-google-id', emailVerified: false);

    app(LoginWithGoogleAction::class)->execute($googleUser);

    $user->refresh();
})->throws(ValidationException::class);

it('does not link the Google ID when the e-mail is unverified', function (): void {
    $user = createUser(['email' => 'victim2@example.com', 'google_id' => null]);

    $googleUser = mockGoogleUser('victim2@example.com', 'attacker-google-id', emailVerified: false);

    try {
        app(LoginWithGoogleAction::class)->execute($googleUser);
    } catch (ValidationException) {
        // expected
    }

    expect($user->fresh()?->google_id)->toBeNull();
});

it('accepts the legacy verified_email claim from the OAuth2 endpoint', function (): void {
    config()->set('qasa.features.registration', true);

    $googleUser = new Laravel\Socialite\Two\User;
    $googleUser->map([
        'id' => 'google-legacy',
        'email' => 'legacy@example.com',
        'name' => 'Legacy User',
        'avatar' => null,
    ]);
    $googleUser->setRaw(['verified_email' => true]);

    app(LoginWithGoogleAction::class)->execute($googleUser);

    expect(User::query()->where('email', 'legacy@example.com')->exists())->toBeTrue();
});

it('logs in with a verified Google e-mail', function (): void {
    $user = createUser(['email' => 'verified@example.com', 'google_id' => null]);

    $googleUser = mockGoogleUser('verified@example.com', 'google-42');

    $result = app(LoginWithGoogleAction::class)->execute($googleUser);

    expect($result->token)->toBeString()->not->toBeEmpty()
        ->and($user->fresh()?->google_id)->toBe('google-42');
});
