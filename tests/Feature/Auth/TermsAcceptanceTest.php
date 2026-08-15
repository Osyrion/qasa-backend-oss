<?php

declare(strict_types=1);

use App\Modules\Auth\Application\Actions\LoginWithGoogleAction;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Domain\Models\ActivityLog;
use App\Modules\Shared\Support\AccountLookup;

/*
|--------------------------------------------------------------------------
| GDPR phase 3 — terms/privacy consent record
| (docs/plans/GDPR_COMPLIANCE_PLAN.md)
|--------------------------------------------------------------------------
*/

// ── Registration ─────────────────────────────────────────────────────────

it('rejects registration without accepted_terms', function (): void {
    config()->set('qasa.features.registration', true);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ján',
        'surname' => 'Novák',
        'email' => 'noterms@example.com',
        'password' => 'super-secret-1',
    ])->assertStatus(422)->assertJsonValidationErrors('accepted_terms');

    expect(AccountLookup::byEmail('noterms@example.com'))->toBeNull();
});

it('rejects registration when accepted_terms is explicitly false', function (): void {
    config()->set('qasa.features.registration', true);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ján',
        'surname' => 'Novák',
        'email' => 'falseterms@example.com',
        'password' => 'super-secret-1',
        'accepted_terms' => false,
    ])->assertStatus(422)->assertJsonValidationErrors('accepted_terms');
});

it('stamps terms_accepted_at and the current terms_version on registration', function (): void {
    config()->set('qasa.features.registration', true);
    config()->set('gdpr.terms_version', '2.3');

    $before = now();

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ján',
        'surname' => 'Novák',
        'email' => 'terms@example.com',
        'password' => 'super-secret-1',
        'accepted_terms' => true,
    ])->assertCreated();

    $account = AccountLookup::byEmail('terms@example.com') ?? throw new RuntimeException('account not found');
    $user = asAccount($account, fn () => User::query()->where('email', 'terms@example.com')->firstOrFail());

    expect($user->terms_version)->toBe('2.3')
        ->and($user->terms_accepted_at)->not->toBeNull()
        // terms_accepted_at column has whole-second precision, so a strict
        // >= against a $before timestamp with microseconds can read as
        // "earlier" once the fraction is truncated on write.
        ->and($user->terms_accepted_at->diffInSeconds($before, absolute: true))->toBeLessThan(5);
});

it('records auth.terms_accepted in the activity log on registration', function (): void {
    config()->set('qasa.features.registration', true);
    config()->set('gdpr.terms_version', '1.5');

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ján',
        'surname' => 'Novák',
        'email' => 'termslog@example.com',
        'password' => 'super-secret-1',
        'accepted_terms' => true,
    ])->assertCreated();

    $account = AccountLookup::byEmail('termslog@example.com') ?? throw new RuntimeException('account not found');

    $entry = asAccount($account, fn () => ActivityLog::withoutGlobalScope('user')
        ->where('user_id', $account)
        ->where('event', 'auth.terms_accepted')
        ->firstOrFail());

    expect($entry->changes)->toBe(['version' => '1.5']);
});

// ── Google ────────────────────────────────────────────────────────────────

it('records terms acceptance for a newly-registered Google account', function (): void {
    config()->set('qasa.features.registration', true);
    config()->set('gdpr.terms_version', '3.0');

    $googleUser = mockGoogleUser('google-terms@example.com', 'google-terms-1', name: 'Peter Kováč');

    app(LoginWithGoogleAction::class)->execute($googleUser);

    $user = User::query()->where('email', 'google-terms@example.com')->firstOrFail();

    expect($user->terms_version)->toBe('3.0')
        ->and($user->terms_accepted_at)->not->toBeNull();
});

it('does not overwrite terms acceptance when Google links to an existing account', function (): void {
    config()->set('gdpr.terms_version', '2.0');

    $user = createUser([
        'email' => 'existing-terms@example.com',
        'google_id' => null,
        'terms_accepted_at' => now()->subYear(),
        'terms_version' => '1.0',
    ]);

    $googleUser = mockGoogleUser('existing-terms@example.com', 'google-link-1');

    app(LoginWithGoogleAction::class)->execute($googleUser);

    asAccount($user, function () use ($user): void {
        $user->refresh();

        expect($user->terms_version)->toBe('1.0')
            ->and($user->google_id)->toBe('google-link-1');
    });
});

// ── POST /profile/accept-terms ───────────────────────────────────────────

it('lets an existing account accept the current terms version', function (): void {
    config()->set('gdpr.terms_version', '4.0');

    $user = createUser(['terms_accepted_at' => null, 'terms_version' => null]);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/profile/accept-terms')
        ->assertNoContent();

    asAccount($user, function () use ($user): void {
        $user->refresh();

        expect($user->terms_version)->toBe('4.0')
            ->and($user->terms_accepted_at)->not->toBeNull();
    });
});

it('moves an outdated terms_version forward via accept-terms', function (): void {
    config()->set('gdpr.terms_version', '5.0');

    $user = createUser(['terms_accepted_at' => now()->subYears(2), 'terms_version' => '1.0']);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/profile/accept-terms')
        ->assertNoContent();

    asAccount($user, fn () => expect($user->refresh()->terms_version)->toBe('5.0'));
});

it('requires authentication for accept-terms', function (): void {
    $this->postJson('/api/v1/profile/accept-terms')->assertUnauthorized();
});

// ── UserResource flag ─────────────────────────────────────────────────────

it('flags terms_acceptance_required when the account never accepted', function (): void {
    config()->set('gdpr.terms_version', '1.0');

    $user = createUser(['terms_accepted_at' => null, 'terms_version' => null]);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.terms_acceptance_required', true);
});

it('flags terms_acceptance_required when the accepted version is outdated', function (): void {
    config()->set('gdpr.terms_version', '2.0');

    $user = createUser(['terms_accepted_at' => now(), 'terms_version' => '1.0']);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.terms_acceptance_required', true);
});

it('clears terms_acceptance_required once the current version is accepted', function (): void {
    config()->set('gdpr.terms_version', '2.0');

    $user = createUser(['terms_accepted_at' => now(), 'terms_version' => '2.0']);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.terms_acceptance_required', false);
});
