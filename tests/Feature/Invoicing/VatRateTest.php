<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;
use App\Modules\Invoicing\Domain\Models\VatRate;
use App\Modules\Shared\Support\AccountLookup;

it('creates, lists, updates and deletes VAT rates', function (): void {
    $user = createUser();

    $create = $this->actingAs($user)->postJson('/api/v1/vat-rates', [
        'code' => 'SK-23',
        'country' => 'SK',
        'rate' => 23,
        'is_default' => true,
    ]);

    $create->assertCreated();
    $id = $create->json('data.id');

    $this->actingAs($user)->getJson('/api/v1/vat-rates')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($user)->putJson("/api/v1/vat-rates/{$id}", [
        'code' => 'SK-23',
        'country' => 'SK',
        'rate' => 23,
        'label' => 'Základná sadzba',
        'is_default' => true,
    ])->assertOk()->assertJsonPath('data.label', 'Základná sadzba');

    $this->actingAs($user)->deleteJson("/api/v1/vat-rates/{$id}")->assertNoContent();

    expect(VatRate::withoutGlobalScope('user')->count())->toBe(0);
});

it('hides other users VAT rates', function (): void {
    $owner = createUser();
    $rate = VatRate::factory()->create(['user_id' => $owner->id]);

    $intruder = createUser();

    $this->actingAs($intruder)->getJson("/api/v1/vat-rates/{$rate->id}")->assertNotFound();
    $this->actingAs($intruder)->getJson('/api/v1/vat-rates')->assertOk()->assertJsonCount(0, 'data');
});

it('keeps a single default per user and country', function (): void {
    $user = createUser();

    $first = VatRate::factory()->create([
        'user_id' => $user->id, 'country' => 'SK', 'code' => 'SK-23', 'rate' => 23, 'is_default' => true,
    ]);

    $this->actingAs($user)->postJson('/api/v1/vat-rates', [
        'code' => 'SK-5',
        'country' => 'SK',
        'rate' => 5,
        'is_default' => true,
    ])->assertCreated();

    expect($first->refresh()->is_default)->toBeFalse()
        ->and(VatRate::withoutGlobalScope('user')->where('is_default', true)->count())->toBe(1);
});

it('allows the same code for two different tenants', function (): void {
    $a = createUser();
    $b = createUser();

    $this->actingAs($a)->postJson('/api/v1/vat-rates', [
        'code' => 'SK-23', 'country' => 'SK', 'rate' => 23,
    ])->assertCreated();

    $this->actingAs($b)->postJson('/api/v1/vat-rates', [
        'code' => 'SK-23', 'country' => 'SK', 'rate' => 23,
    ])->assertCreated();

    // Counted inside each account's own binding: dropping the Eloquent scope
    // no longer means seeing across accounts — the policy still applies.
    expect(asAccount($a, fn () => VatRate::withoutGlobalScope('user')->where('code', 'SK-23')->count()))->toBe(1)
        ->and(asAccount($b, fn () => VatRate::withoutGlobalScope('user')->where('code', 'SK-23')->count()))->toBe(1);
});

it('rejects a duplicate code for the same tenant', function (): void {
    $user = createUser();
    VatRate::factory()->create(['user_id' => $user->id, 'code' => 'SK-23']);

    $this->actingAs($user)->postJson('/api/v1/vat-rates', [
        'code' => 'SK-23', 'country' => 'SK', 'rate' => 23,
    ])->assertUnprocessable()->assertJsonValidationErrors('code');
});

it('seeds no VAT rate catalog on registration — only after complete-residency (step 2)', function (): void {
    config()->set('qasa.features.registration', true);

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Ján',
        'surname' => 'Novák',
        'email' => 'jan@example.com',
        'password' => 'super-secret-1',
    ])->assertCreated();

    // Registered through HTTP, so nothing is bound once the request has
    // terminated — users is tenant-scoped too now (phase 7), so both the
    // fetch and the verify-email write need the account back first.
    $account = AccountLookup::byEmail('jan@example.com') ?? throw new RuntimeException('account not found');
    $user = asAccount($account, function (): User {
        $user = User::query()->where('email', 'jan@example.com')->firstOrFail();
        $user->markEmailAsVerified();

        return $user;
    });
    $token = $response->json('token');

    // Registered through HTTP rather than the test helper, so nothing is
    // bound once the request has terminated and the catalog is invisible
    // until the test says whose it is looking at.
    expect(asAccount($user, fn () => VatRate::withoutGlobalScope('user')->where('user_id', $user->id)->count()))->toBe(0);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/complete-residency', [
            'country' => 'SK',
            'ico' => '11000000',
            'company_name' => 'Ján Novák',
            'address' => 'Hlavná 1',
            'city' => 'Bratislava',
            'postal_code' => '81101',
        ])->assertOk();

    $rates = asAccount($user, fn () => VatRate::withoutGlobalScope('user')->where('user_id', $user->id)->get());

    expect($rates->pluck('rate')->map(fn ($rate) => (float) $rate)->sort()->values()->all())
        ->toBe([0.0, 5.0, 10.0, 23.0])
        ->and($rates->firstWhere('is_default', true)?->rate)->toEqual(23.0);
});

it('is idempotent when seeding twice for the same account', function (): void {
    $user = createUser(['country' => 'SK']);

    app(VatRateSeederService::class)->seedFor($user);
    app(VatRateSeederService::class)->seedFor($user);

    expect(VatRate::withoutGlobalScope('user')->where('user_id', $user->id)->count())->toBe(4);
});

it('backfills VAT rates for accounts missing them via the console command', function (): void {
    $user = createUser(['country' => 'CZ']);

    expect(VatRate::withoutGlobalScope('user')->where('user_id', $user->id)->count())->toBe(0);

    $this->artisan('qasa:invoices:backfill-vat-rates')->assertSuccessful();

    $rates = VatRate::withoutGlobalScope('user')->where('user_id', $user->id)->get();

    expect($rates->pluck('rate')->map(fn ($rate) => (float) $rate)->sort()->values()->all())
        ->toBe([0.0, 12.0, 21.0]);

    // Re-running must not duplicate rows.
    $this->artisan('qasa:invoices:backfill-vat-rates')->assertSuccessful();
    expect(VatRate::withoutGlobalScope('user')->where('user_id', $user->id)->count())->toBe(3);
});
