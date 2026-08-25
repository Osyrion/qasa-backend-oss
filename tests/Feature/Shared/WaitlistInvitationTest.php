<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Actions\InviteFromWaitlistAction;
use App\Modules\Shared\Application\Notifications\WaitlistInvitationNotification;
use App\Modules\Shared\Domain\Models\WaitlistSignup;
use App\Modules\Shared\Support\AccountLookup;
use Illuminate\Support\Facades\Notification;

/**
 * The beta runs on a closed registration: `qasa.features.registration` is off
 * and an invitation off the waitlist is the only way to an account. That
 * makes the token the door, so most of what matters here is what it refuses.
 */
beforeEach(function (): void {
    Notification::fake();
    config()->set('qasa.features.registration', false);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function signup(array $attributes = []): WaitlistSignup
{
    return WaitlistSignup::query()->create(array_merge([
        'email' => 'waiting@example.test',
        'locale' => 'sk',
    ], $attributes));
}

/**
 * @return array<string, mixed>
 */
function registrationPayloadFor(string $email, ?string $token): array
{
    return array_filter([
        'name' => 'Jana',
        'surname' => 'Nováková',
        'email' => $email,
        'password' => 'correct-horse-9-battery',
        'accepted_terms' => true,
        'invitation_token' => $token,
    ], fn ($value): bool => $value !== null);
}

it('mails an invitation and remembers only its hash', function (): void {
    $row = signup();

    $token = app(InviteFromWaitlistAction::class)->execute($row);

    Notification::assertSentOnDemand(WaitlistInvitationNotification::class);

    $row->refresh();

    expect($row->invited_at)->not->toBeNull()
        ->and($row->invite_count)->toBe(1)
        ->and($row->invitation_expires_at?->isFuture())->toBeTrue()
        // The raw token must never be recoverable from the row.
        ->and($row->invitation_token_hash)->not->toBe($token)
        ->and($row->invitation_token_hash)->toBe(hash('sha256', $token));
});

it('refuses registration outright without an invitation', function (): void {
    $this->postJson('/api/v1/auth/register', registrationPayloadFor('nobody@example.test', null))
        ->assertStatus(404);
});

it('lets an invited address register while registration is closed', function (): void {
    $row = signup(['email' => 'invited@example.test']);
    $token = app(InviteFromWaitlistAction::class)->execute($row);

    $this->postJson('/api/v1/auth/register', registrationPayloadFor('invited@example.test', $token))
        ->assertCreated();

    $row->refresh();

    expect($row->registered_at)->not->toBeNull()
        // Burnt on the way through: one invitation, one account.
        ->and($row->invitation_token_hash)->toBeNull();
});

it('will not let an invitation be forwarded to somebody else', function (): void {
    $row = signup(['email' => 'invited@example.test']);
    $token = app(InviteFromWaitlistAction::class)->execute($row);

    // Without the address check the token is a skeleton key and the closed
    // beta is open to anyone the mail gets forwarded to.
    $this->postJson('/api/v1/auth/register', registrationPayloadFor('someone.else@example.test', $token))
        ->assertStatus(422);

    expect(AccountLookup::byEmail('someone.else@example.test'))->toBeNull();
    expect($row->refresh()->registered_at)->toBeNull();
});

it('refuses a token that has already been redeemed', function (): void {
    $row = signup(['email' => 'invited@example.test']);
    $token = app(InviteFromWaitlistAction::class)->execute($row);

    $this->postJson('/api/v1/auth/register', registrationPayloadFor('invited@example.test', $token))
        ->assertCreated();

    // Same link, opened a second time — by the recipient or by anyone the
    // mail was forwarded to afterwards.
    $this->postJson('/api/v1/auth/register', registrationPayloadFor('second@example.test', $token))
        ->assertStatus(404);
});

it('refuses an expired invitation', function (): void {
    $row = signup(['email' => 'invited@example.test']);
    $token = app(InviteFromWaitlistAction::class)->execute($row);

    $row->forceFill(['invitation_expires_at' => now()->subDay()])->save();

    $this->postJson('/api/v1/auth/register', registrationPayloadFor('invited@example.test', $token))
        ->assertStatus(404);
});

it('replaces the token when somebody is invited again', function (): void {
    $row = signup();

    $first = app(InviteFromWaitlistAction::class)->execute($row);
    $second = app(InviteFromWaitlistAction::class)->execute($row);

    expect($second)->not->toBe($first)
        ->and($row->refresh()->invite_count)->toBe(2);

    // The superseded link has to stop working, or a resend quietly doubles
    // the number of ways in.
    $this->postJson('/api/v1/auth/register', registrationPayloadFor('waiting@example.test', $first))
        ->assertStatus(404);
});

it('still lets anyone register once registration is open', function (): void {
    config()->set('qasa.features.registration', true);

    $this->postJson('/api/v1/auth/register', registrationPayloadFor('walkin@example.test', null))
        ->assertCreated();
});
