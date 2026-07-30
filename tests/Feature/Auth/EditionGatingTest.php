<?php

declare(strict_types=1);

use App\Modules\Auth\Application\Actions\LoginWithGoogleAction;
use App\Modules\Shared\Support\AccountLookup;
use Illuminate\Validation\ValidationException;

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

    $googleUser = mockGoogleUser('unknown@example.com', 'google-999');

    app(LoginWithGoogleAction::class)->execute($googleUser);
})->throws(ValidationException::class);

it('still logs in an existing user via google when registration is disabled', function (): void {
    config()->set('qasa.features.registration', false);

    $user = createUser(['email' => 'existing@example.com']);

    $googleUser = mockGoogleUser('existing@example.com', 'google-123');

    $result = app(LoginWithGoogleAction::class)->execute($googleUser);

    $user->refresh();

    expect($result->twoFactorRequired)->toBeFalse()
        ->and($result->token)->toBeString()->not->toBeEmpty()
        ->and($user->google_id)->toBe('google-123');
});

it('creates a user via the qasa:user command', function (): void {
    $this->artisan('qasa:user', [
        '--name' => 'Cli',
        '--surname' => 'User',
        '--email' => 'cli@example.com',
        '--password' => 'super-secret-1',
        '--country' => 'SK',
        '--ico' => '11000000',
    ])->assertSuccessful();

    // qasa:user binds only for the insert itself, then restores whatever
    // was bound before (nothing) — users is tenant-scoped now (phase 7), and
    // roleName() on the SaaS model queries model_has_roles, tenant-scoped
    // too, so the whole assertion needs to stay inside the same bound
    // callback rather than just the initial fetch.
    $account = AccountLookup::byEmail('cli@example.com') ?? throw new RuntimeException('account not found');

    asAccount($account, function () {
        $user = userModel()::query()->where('email', 'cli@example.com')->firstOrFail();

        expect($user->email_verified_at)->not->toBeNull()
            ->and($user->hasPassword())->toBeTrue()
            ->and($user->roleName())->toBe('owner')
            ->and($user->country)->toBe('SK')
            ->and($user->ico)->toBe('11000000');
    });
});

it('rejects a duplicate e-mail in the qasa:user command', function (): void {
    createUser(['email' => 'taken@example.com']);

    $this->artisan('qasa:user', [
        '--name' => 'Cli',
        '--surname' => 'User',
        '--email' => 'taken@example.com',
        '--password' => 'super-secret-1',
        '--country' => 'SK',
        '--ico' => '11000000',
    ])->assertFailed();
});

it('rejects an invalid IČO in the qasa:user command', function (): void {
    $this->artisan('qasa:user', [
        '--name' => 'Cli',
        '--surname' => 'User',
        '--email' => 'bad-ico@example.com',
        '--password' => 'super-secret-1',
        '--country' => 'SK',
        '--ico' => '00000001',
    ])->assertFailed();
});
