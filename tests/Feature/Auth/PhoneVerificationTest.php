<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Contracts\PhoneVerificationProviderInterface;
use App\Modules\Auth\Domain\Events\PhoneVerified;
use App\Modules\Auth\Domain\Models\PhoneVerificationCode;
use App\Modules\Shared\Support\AccountLookup;
use App\Modules\Shared\Support\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| SMS phone verification
| (docs/plans/PHONE_VERIFICATION_TRIAL_ABUSE_PLAN.md)
|--------------------------------------------------------------------------
*/

/**
 * A gateway that records what it was asked to send instead of sending it, so
 * a test can read the code back without it ever leaving the process.
 */
final class RecordingSmsProvider implements PhoneVerificationProviderInterface
{
    /** @var list<array{phone: string, code: string}> */
    public array $sent = [];

    public function __construct(private readonly bool $succeeds = true) {}

    public function sendCode(string $phone, string $code): bool
    {
        $this->sent[] = ['phone' => $phone, 'code' => $code];

        return $this->succeeds;
    }
}

/**
 * @param  bool  $succeeds  Whether the gateway accepts the message.
 */
function fakeSmsProvider(bool $succeeds = true): RecordingSmsProvider
{
    $spy = new RecordingSmsProvider($succeeds);

    app()->instance(PhoneVerificationProviderInterface::class, $spy);

    return $spy;
}

beforeEach(function (): void {
    config()->set('qasa.features.phone_verification', true);
    // Cooldown off unless a test is specifically about it — otherwise every
    // multi-send test would need a time-travel dance to say anything else.
    config()->set('qasa.phone_verification.resend_cooldown_seconds', 0);
});

// ── Sending ──────────────────────────────────────────────────────────────

it('sends a code and stores only its hash', function (): void {
    $user = createUser();
    $provider = fakeSmsProvider();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])
        ->assertOk();

    expect($provider->sent)->toHaveCount(1)
        ->and($provider->sent[0]['phone'])->toBe('+421900123456');

    $record = PhoneVerificationCode::query()->where('user_id', $user->id)->sole();

    // The point of the column: the plaintext must not be recoverable from
    // the row, only checkable against it.
    expect($record->code_hash)->not->toBe($provider->sent[0]['code'])
        ->and(Hash::check($provider->sent[0]['code'], $record->code_hash))->toBeTrue();
});

it('strips separators from the submitted number', function (): void {
    $user = createUser();
    $provider = fakeSmsProvider();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421 900 123-456'])
        ->assertOk();

    expect($provider->sent[0]['phone'])->toBe('+421900123456');
});

it('rejects a number that is not E.164', function (): void {
    $user = createUser();
    fakeSmsProvider();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/phone/send-code', ['phone' => '0900123456'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone');
});

it('reports 503 rather than 500 when the gateway fails', function (): void {
    $user = createUser();
    fakeSmsProvider(succeeds: false);

    $this->actingAs($user)
        ->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])
        ->assertStatus(503);

    // A failed send must not leave a code behind — it would start a cooldown
    // the user never earned, for a message they never got.
    expect(PhoneVerificationCode::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('reports 503 when a provider throws despite the contract', function (): void {
    $user = createUser();

    app()->instance(PhoneVerificationProviderInterface::class, new class implements PhoneVerificationProviderInterface
    {
        public function sendCode(string $phone, string $code): bool
        {
            throw new RuntimeException('gateway exploded');
        }
    });

    $this->actingAs($user)
        ->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])
        ->assertStatus(503);
});

it('reports 503 when the feature is switched off', function (): void {
    config()->set('qasa.features.phone_verification', false);

    $user = createUser();
    $provider = fakeSmsProvider();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])
        ->assertStatus(503);

    expect($provider->sent)->toBeEmpty();
});

it('refuses to resend inside the cooldown', function (): void {
    config()->set('qasa.phone_verification.resend_cooldown_seconds', 60);

    $user = createUser();
    fakeSmsProvider();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])
        ->assertOk();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])
        ->assertStatus(422);
});

it('kills the previous code when a new one is requested', function (): void {
    $user = createUser();
    $provider = fakeSmsProvider();

    $this->actingAs($user)->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])->assertOk();
    $this->actingAs($user)->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])->assertOk();

    // The first code must no longer work, or two live codes would double an
    // attacker's guesses per account.
    $this->actingAs($user)
        ->postJson('/api/v1/auth/phone/verify', ['code' => $provider->sent[0]['code']])
        ->assertStatus(422);

    $this->actingAs($user)
        ->postJson('/api/v1/auth/phone/verify', ['code' => $provider->sent[1]['code']])
        ->assertOk();
});

// ── Verifying ────────────────────────────────────────────────────────────

it('verifies the number and fires PhoneVerified', function (): void {
    Event::fake([PhoneVerified::class]);

    $user = createUser();
    $provider = fakeSmsProvider();

    $this->actingAs($user)->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])->assertOk();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/phone/verify', ['code' => $provider->sent[0]['code']])
        ->assertOk();

    $user->refresh();

    expect($user->phone)->toBe('+421900123456')
        ->and($user->phone_verified_at)->not->toBeNull();

    Event::assertDispatched(PhoneVerified::class);
});

it('rejects a wrong code and counts the attempt', function (): void {
    $user = createUser();
    fakeSmsProvider();

    $this->actingAs($user)->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])->assertOk();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/phone/verify', ['code' => '000000'])
        ->assertStatus(422);

    $record = PhoneVerificationCode::query()->where('user_id', $user->id)->sole();

    // The whole attempt cap rests on this surviving the exception that the
    // wrong guess threw — an increment rolled back with the request would
    // leave a six-digit secret guessable at HTTP speed.
    expect($record->attempts)->toBe(1);
});

it('locks the code after the configured number of attempts', function (): void {
    config()->set('qasa.phone_verification.max_attempts', 3);

    $user = createUser();
    $provider = fakeSmsProvider();

    $this->actingAs($user)->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])->assertOk();

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($user)->postJson('/api/v1/auth/phone/verify', ['code' => '000000'])->assertStatus(422);
    }

    // Even the right code is refused now — the cap is on the code, not on
    // how wrong the guesses were.
    $this->actingAs($user)
        ->postJson('/api/v1/auth/phone/verify', ['code' => $provider->sent[0]['code']])
        ->assertStatus(422);

    expect($user->refresh()->phone_verified_at)->toBeNull();
});

it('rejects an expired code', function (): void {
    config()->set('qasa.phone_verification.code_ttl_minutes', 10);

    $user = createUser();
    $provider = fakeSmsProvider();

    $this->actingAs($user)->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])->assertOk();

    $this->travel(11)->minutes();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/phone/verify', ['code' => $provider->sent[0]['code']])
        ->assertStatus(422);

    expect($user->refresh()->phone_verified_at)->toBeNull();
});

// ── One number, one account ──────────────────────────────────────────────

it('refuses a number another account has already verified', function (): void {
    $incumbent = createUser();
    $provider = fakeSmsProvider();

    $this->actingAs($incumbent)->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])->assertOk();
    $this->actingAs($incumbent)->postJson('/api/v1/auth/phone/verify', ['code' => $provider->sent[0]['code']])->assertOk();

    $newcomer = createUser();

    $this->actingAs($newcomer)
        ->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])
        ->assertStatus(422);
});

it('lets the loser of a verification race find out at confirm time', function (): void {
    $first = createUser();
    $second = createUser();
    $provider = fakeSmsProvider();

    // Both hold a live code for the same number: nothing at send time can
    // stop that, because neither has verified it yet.
    $this->actingAs($first)->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])->assertOk();
    $this->actingAs($second)->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])->assertOk();

    $this->actingAs($first)->postJson('/api/v1/auth/phone/verify', ['code' => $provider->sent[0]['code']])->assertOk();

    $this->actingAs($second)
        ->postJson('/api/v1/auth/phone/verify', ['code' => $provider->sent[1]['code']])
        ->assertStatus(422);

    expect($second->refresh()->phone_verified_at)->toBeNull();
});

it('does not treat an unverified number as taken', function (): void {
    // Two accounts may perfectly well have typed the same number into their
    // profile before any of this existed; only a proved claim reserves one.
    $squatter = createUser(['phone' => '+421900123456']);
    $user = createUser();

    fakeSmsProvider();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])
        ->assertOk();

    // Re-read under the squatter's own tenant: RLS hides another account's
    // users row, so a plain refresh() here returns null and asserts nothing.
    $squatterAfter = TenantContext::for($squatter->id, fn () => $squatter->fresh());

    expect(AccountLookup::byPhone('+421900123456'))->toBeNull()
        ->and($squatterAfter?->phone_verified_at)->toBeNull();
});

it('keeps a verified number reserved while the account is only soft-deleted', function (): void {
    $leaver = createUser();
    $provider = fakeSmsProvider();

    $this->actingAs($leaver)->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])->assertOk();
    $this->actingAs($leaver)->postJson('/api/v1/auth/phone/verify', ['code' => $provider->sent[0]['code']])->assertOk();

    TenantContext::for($leaver->id, fn () => $leaver->delete());

    // Releasing it here would undo the entire feature: delete the account,
    // register again with the same handset, collect another trial, no
    // waiting at all. The purge is what eventually frees it.
    expect(AccountLookup::byPhone('+421900123456'))->toBe($leaver->id);

    $newcomer = createUser();

    $this->actingAs($newcomer)
        ->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])
        ->assertStatus(422);
});

it('releases the number once the account is purged', function (): void {
    $leaver = createUser();
    $provider = fakeSmsProvider();

    $this->actingAs($leaver)->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])->assertOk();
    $this->actingAs($leaver)->postJson('/api/v1/auth/phone/verify', ['code' => $provider->sent[0]['code']])->assertOk();

    TenantContext::for($leaver->id, fn () => $leaver->delete());

    // The number is personal data — it cannot stay reserved forever just to
    // hold a slot. What outlives the purge is the trial claim, not the
    // number (see TrialEligibilityTest).
    $this->travel((int) config('gdpr.account_purge_grace_days') + 1)->days();
    $this->artisan('qasa:accounts:purge')->assertSuccessful();

    expect(AccountLookup::byPhone('+421900123456'))->toBeNull();
});

// ── Changing a verified number ───────────────────────────────────────────

it('lets a verified account move to a different number', function (): void {
    config()->set('qasa.phone_verification.change_cooldown_days', 0);

    $user = createUser();
    $provider = fakeSmsProvider();

    $this->actingAs($user)->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900111111'])->assertOk();
    $this->actingAs($user)->postJson('/api/v1/auth/phone/verify', ['code' => $provider->sent[0]['code']])->assertOk();

    // People switch operators, lose handsets, leave a company number behind.
    $this->actingAs($user)->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900222222'])->assertOk();
    $this->actingAs($user)->postJson('/api/v1/auth/phone/verify', ['code' => $provider->sent[1]['code']])->assertOk();

    expect($user->refresh()->phone)->toBe('+421900222222');

    // The old number goes back into circulation — it is nobody's now.
    expect(AccountLookup::byPhone('+421900111111'))->toBeNull();
});

it('holds a freshly verified number for the change cooldown', function (): void {
    config()->set('qasa.phone_verification.change_cooldown_days', 3);

    $user = createUser();
    $provider = fakeSmsProvider();

    $this->actingAs($user)->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900111111'])->assertOk();
    $this->actingAs($user)->postJson('/api/v1/auth/phone/verify', ['code' => $provider->sent[0]['code']])->assertOk();

    // Every attempt is a paid SMS, and the per-number rate limit resets with
    // each new number — so the brake has to be keyed to the account.
    $this->actingAs($user)
        ->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900222222'])
        ->assertStatus(422);

    $this->travel(4)->days();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900222222'])
        ->assertOk();
});

it('does not apply the change cooldown to an account still proving its first number', function (): void {
    config()->set('qasa.phone_verification.change_cooldown_days', 30);

    $user = createUser();
    fakeSmsProvider();

    // Nothing is being changed here, and a trial window may be running out.
    $this->actingAs($user)->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900111111'])->assertOk();
    $this->actingAs($user)->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900222222'])->assertOk();
});

// ── Authentication ───────────────────────────────────────────────────────

it('requires authentication for both endpoints', function (): void {
    $this->postJson('/api/v1/auth/phone/send-code', ['phone' => '+421900123456'])->assertUnauthorized();
    $this->postJson('/api/v1/auth/phone/verify', ['code' => '123456'])->assertUnauthorized();
});
