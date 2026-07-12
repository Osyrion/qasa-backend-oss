<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;
use App\Modules\Invoicing\Domain\Models\VatRate;

it('seeds the SK VAT rate catalog on registration with 23% as default', function (): void {
    config()->set('qasa.features.registration', true);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ján',
        'surname' => 'Novák',
        'email' => 'jan@example.com',
        'password' => 'super-secret-1',
    ])->assertCreated();

    $user = User::query()->where('email', 'jan@example.com')->firstOrFail();

    $rates = VatRate::withoutGlobalScope('user')->where('user_id', $user->id)->get();

    expect($rates->pluck('rate')->map(fn ($rate) => (float) $rate)->sort()->values()->all())
        ->toBe([0.0, 5.0, 19.0, 23.0])
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
