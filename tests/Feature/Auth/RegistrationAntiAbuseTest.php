<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Contracts\PhoneVerificationProviderInterface;
use App\Modules\Shared\Application\Contracts\CaptchaVerifierInterface;
use App\Modules\Shared\Support\AccountLookup;

/*
|--------------------------------------------------------------------------
| Registration anti-abuse — disposable inboxes, captcha, and the standing
| guarantee that none of it can stop an account being created by failing
| (docs/plans/PHONE_VERIFICATION_TRIAL_ABUSE_PLAN.md)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    config()->set('qasa.features.registration', true);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registrationPayload(array $overrides = []): array
{
    return [
        'name' => 'Ján',
        'surname' => 'Novák',
        'email' => 'jan@example.com',
        'password' => 'super-secret-1',
        'accepted_terms' => true,
        ...$overrides,
    ];
}

// ── Disposable inboxes ───────────────────────────────────────────────────

it('refuses a known throwaway inbox domain', function (): void {
    $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'abuser@mailinator.com']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    expect(AccountLookup::byEmail('abuser@mailinator.com'))->toBeNull();
});

it('refuses a subdomain of a throwaway domain', function (): void {
    // Wildcard DNS is exactly how a blocklist keyed on the exact host gets
    // walked past.
    $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'abuser@mail.yopmail.com']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('lets an ordinary address through', function (): void {
    $this->postJson('/api/v1/auth/register', registrationPayload())->assertCreated();

    expect(AccountLookup::byEmail('jan@example.com'))->not->toBeNull();
});

it('is case-insensitive about the domain', function (): void {
    $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'abuser@MailInator.COM']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

// ── Captcha ──────────────────────────────────────────────────────────────

it('passes registration through when the captcha is switched off', function (): void {
    // TurnstileVerifier short-circuits to true when disabled, so a
    // deployment that has never configured Turnstile is unaffected.
    config()->set('services.turnstile.enabled', false);

    $this->postJson('/api/v1/auth/register', registrationPayload())->assertCreated();
});

it('refuses registration when the captcha rejects the caller', function (): void {
    app()->instance(CaptchaVerifierInterface::class, new class implements CaptchaVerifierInterface
    {
        public function verify(?string $token, ?string $remoteIp): bool
        {
            return false;
        }
    });

    $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(422);

    expect(AccountLookup::byEmail('jan@example.com'))->toBeNull();
});

// ── The standing guarantee ───────────────────────────────────────────────

it('registers an account even when the SMS gateway is dead', function (): void {
    config()->set('qasa.features.phone_verification', true);

    app()->instance(PhoneVerificationProviderInterface::class, new class implements PhoneVerificationProviderInterface
    {
        public function sendCode(string $phone, string $code): bool
        {
            throw new RuntimeException('gateway exploded');
        }
    });

    // The whole requirement in one test: verification is a step after
    // registration, so nothing the provider does can reach this endpoint.
    $this->postJson('/api/v1/auth/register', registrationPayload())->assertCreated();

    expect(AccountLookup::byEmail('jan@example.com'))->not->toBeNull();
});

it('registers an account when phone verification is switched off entirely', function (): void {
    config()->set('qasa.features.phone_verification', false);

    $this->postJson('/api/v1/auth/register', registrationPayload())->assertCreated();
});

it('does not accept a phone number at registration', function (): void {
    // Collecting it here would mean a number verified elsewhere had to fail
    // the request — and would turn a public endpoint into an oracle for
    // which numbers hold accounts.
    $this->postJson('/api/v1/auth/register', registrationPayload(['phone' => '+421900123456']))
        ->assertCreated();

    $owner = AccountLookup::byEmail('jan@example.com');

    expect($owner)->not->toBeNull()
        ->and(userModel()::withoutGlobalScope('user')->find($owner)?->phone)->toBeNull();
});
