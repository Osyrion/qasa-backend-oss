<?php

declare(strict_types=1);

use App\Modules\Auth\Application\Actions\LoginWithGoogleAction;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Domain\Models\ActivityLog;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Support\AccountLookup;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Phase 1 — two-step registration + mandatory residency
| (docs/plans/TAX_RESIDENCY_PHASE_1_REGISTRATION_RESIDENCY.md)
|--------------------------------------------------------------------------
*/

// ── Step 1: account creation, no residency ──────────────────────────────────

it('registers a user via email/password with no residency and no IČO — never a "SK" default', function (): void {
    config()->set('qasa.features.registration', true);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ján',
        'surname' => 'Novák',
        'email' => 'step1@example.com',
        'password' => 'super-secret-1',
        'accepted_terms' => true,
    ])->assertCreated();

    // Registration is unauthenticated, so the middleware clears the binding
    // on the way out — users is tenant-scoped too now (phase 7), so reading
    // the row back needs AccountLookup to find its account first.
    $account = AccountLookup::byEmail('step1@example.com') ?? throw new RuntimeException('account not found');
    $user = asAccount(
        $account,
        fn () => User::query()->where('email', 'step1@example.com')->firstOrFail(),
    );

    expect($user->country)->toBeNull()
        ->and($user->ico)->toBeNull()
        ->and($user->hasTaxResidency())->toBeFalse();
});

it('creates a Google-registered user with no residency', function (): void {
    config()->set('qasa.features.registration', true);

    $googleUser = mockGoogleUser('google-step1@example.com', 'google-456', name: 'Peter Kováč');

    app(LoginWithGoogleAction::class)->execute($googleUser);

    $user = User::query()->where('email', 'google-step1@example.com')->firstOrFail();

    expect($user->country)->toBeNull()
        ->and($user->ico)->toBeNull();
});

// ── Step 2: complete-residency ───────────────────────────────────────────────

/**
 * @return array{0: User, 1: string} the unverified-by-default-country user and its bearer token
 */
function residencyStepUser(): array
{
    $user = createUser(['country' => null, 'ico' => null, 'company_name' => null]);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token];
}

it('rejects an unsupported country at complete-residency', function (): void {
    [, $token] = residencyStepUser();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/complete-residency', [
            'country' => 'DE',
            'ico' => '11000000',
            'company_name' => 'Test s.r.o.',
            'address' => 'Hlavná 1',
            'city' => 'Bratislava',
            'postal_code' => '81101',
        ])->assertStatus(422);
});

it('rejects an invalid IČO checksum at complete-residency', function (): void {
    [, $token] = residencyStepUser();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/complete-residency', [
            'country' => 'SK',
            'ico' => '11000001',
            'company_name' => 'Test s.r.o.',
            'address' => 'Hlavná 1',
            'city' => 'Bratislava',
            'postal_code' => '81101',
        ])->assertStatus(422);
});

it('rejects missing billing details at complete-residency', function (): void {
    [, $token] = residencyStepUser();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/complete-residency', [
            'country' => 'SK',
            'ico' => '11000000',
        ])->assertStatus(422);
});

it('completes residency for a valid SK IČO with full billing details, prefilling EUR', function (): void {
    [$user, $token] = residencyStepUser();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/complete-residency', [
            'country' => 'SK',
            'ico' => '11000000',
            'dic' => '1234567890',
            'vat_id' => 'SK1234567890',
            'company_name' => 'Ján Novák — JN Services',
            'address' => 'Hlavná 1',
            'city' => 'Bratislava',
            'postal_code' => '81101',
        ])->assertOk();

    $response->assertJsonPath('data.country', 'SK')
        ->assertJsonPath('data.has_tax_residency', true)
        ->assertJsonPath('data.company_name', 'Ján Novák — JN Services')
        ->assertJsonPath('data.default_currency', 'EUR');

    // The Bearer-token request above cleared the binding on its way out —
    // users is tenant-scoped too now (phase 7), so refresh() needs it back.
    expect(asAccount($user, fn () => $user->refresh())->ico)->toBe('11000000');
});

it('completes residency for a valid CZ IČO, prefilling CZK', function (): void {
    [, $token] = residencyStepUser();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/complete-residency', [
            'country' => 'CZ',
            'ico' => '12345679',
            'dic' => 'CZ12345679',
            'company_name' => 'Firma s.r.o.',
            'address' => 'Hlavní 1',
            'city' => 'Praha',
            'postal_code' => '11000',
        ])->assertOk()
        ->assertJsonPath('data.country', 'CZ')
        ->assertJsonPath('data.default_currency', 'CZK');
});

it('rejects complete-residency for an unverified email', function (): void {
    $user = createUser(['country' => null, 'email_verified_at' => null]);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/complete-residency', [
            'country' => 'SK',
            'ico' => '11000000',
            'company_name' => 'Test s.r.o.',
            'address' => 'Hlavná 1',
            'city' => 'Bratislava',
            'postal_code' => '81101',
        ])->assertStatus(403);
});

it('rejects a repeated complete-residency call once residency is set', function (): void {
    $user = createUser(['country' => 'SK']);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/complete-residency', [
            'country' => 'CZ',
            'ico' => '12345679',
            'company_name' => 'Test s.r.o.',
            'address' => 'Hlavní 1',
            'city' => 'Praha',
            'postal_code' => '11000',
        ])->assertStatus(422);

    // Same as above — the Bearer-token request cleared the binding on its
    // way out, and users is tenant-scoped now too (phase 7).
    expect(asAccount($user, fn () => $user->refresh())->country)->toBe('SK');
});

// ── registry-lookup ───────────────────────────────────────────────────────

it('requires authentication for registry-lookup', function (): void {
    $this->getJson('/api/v1/auth/registry-lookup?country=SK&ico=11000000')->assertUnauthorized();
});

it('prefills from the registry on a successful lookup', function (): void {
    [, $token] = residencyStepUser();

    Http::fake(['ares.gov.cz/*' => Http::response([
        'ico' => '27074358',
        'obchodniJmeno' => 'ACME s.r.o.',
        'dic' => 'CZ27074358',
        'sidlo' => [
            'kodStatu' => 'CZ', 'nazevObce' => 'Praha', 'nazevUlice' => 'Testovací',
            'cisloDomovni' => '1', 'psc' => '11000', 'textovaAdresa' => 'Testovací 1, 110 00 Praha',
        ],
    ])]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/registry-lookup?country=CZ&ico=27074358')
        ->assertOk()
        ->assertJson(['company_name' => 'ACME s.r.o.', 'country' => 'CZ']);
});

it('returns 422 when the registry is unreachable — client falls back to manual entry', function (): void {
    [, $token] = residencyStepUser();

    Http::fake(['ares.gov.cz/*' => Http::response('', 500)]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/registry-lookup?country=CZ&ico=00000000')
        ->assertStatus(422);
});

it('returns 422 when the IČO is not found in the registry', function (): void {
    [, $token] = residencyStepUser();

    Http::fake(['ares.gov.cz/*' => Http::response('', 404)]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/registry-lookup?country=CZ&ico=00000000')
        ->assertStatus(422);
});

// ── Immutability & IČO lock ──────────────────────────────────────────────────

it('ignores an attempted country change through the profile endpoint', function (): void {
    $user = createUser(['country' => 'SK']);

    $this->actingAs($user)->putJson('/api/v1/auth/profile', [
        'country' => 'CZ',
        'name' => 'Zmenené Meno',
    ])->assertOk();

    expect($user->refresh()->country)->toBe('SK')
        ->and($user->name)->toBe('Zmenené Meno');
});

it('throws at the model level when country is changed away from a non-null value', function (): void {
    $user = createUser(['country' => 'SK']);

    $user->update(['country' => 'CZ']);
})->throws(DomainException::class);

it('allows changing IČO before any document exists, and logs it', function (): void {
    $user = createUser(['country' => 'SK', 'ico' => '11000000']);

    $this->actingAs($user)->putJson('/api/v1/auth/profile', [
        'ico' => '12300000',
    ])->assertOk();

    expect($user->refresh()->ico)->toBe('12300000');

    $entry = ActivityLog::withoutGlobalScope('user')
        ->where('user_id', $user->id)
        ->where('event', 'user.ico_changed')
        ->firstOrFail();

    // Canonicalizing, not identical: changes is a jsonb column, and jsonb
    // stores object keys in its own order. What the entry records is the two
    // values, not the order they were written in.
    expect($entry->changes)->toEqualCanonicalizing(['old_ico' => '11000000', 'new_ico' => '12300000']);
});

it('locks IČO once a document exists on the account', function (): void {
    $user = createUser(['country' => 'SK', 'ico' => '11000000']);
    $client = Client::factory()->create(['user_id' => $user->id]);
    Invoice::factory()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $this->actingAs($user)->putJson('/api/v1/auth/profile', [
        'ico' => '12300000',
    ])->assertStatus(422);

    expect($user->refresh()->ico)->toBe('11000000');
});

it('keeps billing details editable after the IČO lock, but never to an empty value', function (): void {
    $user = createUser(['country' => 'SK', 'ico' => '11000000', 'address' => 'Stará 1']);
    $client = Client::factory()->create(['user_id' => $user->id]);
    Invoice::factory()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $this->actingAs($user)->putJson('/api/v1/auth/profile', [
        'address' => 'Nová 5',
    ])->assertOk();

    expect($user->refresh()->address)->toBe('Nová 5');

    $this->actingAs($user)->putJson('/api/v1/auth/profile', [
        'address' => '',
    ])->assertStatus(422);
});
