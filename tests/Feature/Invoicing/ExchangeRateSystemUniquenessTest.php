<?php

declare(strict_types=1);

use App\Modules\Invoicing\Application\Contracts\CnbRateClientInterface;
use App\Modules\Invoicing\Application\Services\ExchangeRateService;
use App\Modules\Invoicing\Domain\Models\ExchangeRate;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Two rules about the shared rows in `exchange_rates`, both enforced by the
 * database rather than by the code that happens to write them today.
 *
 * `unique_rate_per_day` leads with a nullable `user_id`, and Postgres counts
 * two NULLs as distinct — so the constraint that guards a user's own rates
 * never applied to the system ones at all. `unique_system_rate_per_day` (a
 * partial unique index) is what makes "one ČNB fixing per pair per day" true,
 * and what the upsert's ON CONFLICT clause resolves against: drop the index
 * and the function stops working rather than silently duplicating.
 *
 * The second rule is who may write them. The global fixing is the number
 * every account's foreign-currency invoice is converted with, so the RLS
 * policy lets a tenant connection read the shared rows and not write them;
 * the one way in is upsert_system_exchange_rate(), which the
 * `systemExchangeRate()` test helper calls for exactly the same reason the
 * application does.
 */
it('converges on one system rate per pair and day', function (): void {
    systemExchangeRate('EUR', 'CZK', '2026-08-19', '25.100000');
    systemExchangeRate('EUR', 'CZK', '2026-08-19', '25.900000');

    $rates = ExchangeRate::query()->system()->forPair(Currency::EUR, Currency::CZK)->get();

    expect($rates)->toHaveCount(1)
        ->and((float) $rates->first()?->rate)->toBe(25.9);
});

it('still allows one system rate and one user override for the same day', function (): void {
    $user = createUser();

    systemExchangeRate('EUR', 'CZK', '2026-08-19', '25.100000');

    ExchangeRate::query()->create([
        'user_id' => $user->id,
        'base_currency' => Currency::EUR->value,
        'target_currency' => Currency::CZK->value,
        'rate' => '25.500000',
        'date' => '2026-08-19',
        'source' => 'manual',
    ]);

    expect(ExchangeRate::query()->withoutGlobalScope('user')->forPair(Currency::EUR, Currency::CZK)->count())->toBe(2);
});

it('refuses to write a system rate from a tenant connection', function (): void {
    createUser();

    $insert = null;

    try {
        DB::transaction(fn () => ExchangeRate::query()->create([
            'user_id' => null,
            'base_currency' => Currency::EUR->value,
            'target_currency' => Currency::CZK->value,
            'rate' => '25.100000',
            'date' => '2026-08-19',
            'source' => 'manual',
        ]));
    } catch (QueryException $e) {
        $insert = $e;
    }

    expect($insert)->not->toBeNull('a tenant connection inserted a global exchange rate')
        ->and($insert?->getCode())->toBe('42501');
});

it('refuses to overwrite or delete an existing system rate from a tenant connection', function (): void {
    createUser();
    systemExchangeRate('EUR', 'CZK', '2026-08-19', '25.100000');

    // Only the FOR SELECT policy admits a shared row, so a write does not
    // find one to act on: both statements match nothing, and the rate the
    // account reads a line later is still the one the ČNB published.
    $updated = ExchangeRate::query()->withoutGlobalScope('user')
        ->whereNull('user_id')
        ->update(['rate' => '99.000000']);

    $deleted = ExchangeRate::query()->withoutGlobalScope('user')
        ->whereNull('user_id')
        ->delete();

    expect($updated)->toBe(0, 'a tenant connection overwrote a global exchange rate')
        ->and($deleted)->toBe(0, 'a tenant connection deleted a global exchange rate')
        ->and((float) ExchangeRate::query()->system()->forPair(Currency::EUR, Currency::CZK)->first()?->rate)
        ->toBe(25.1);
});

/**
 * The index must not turn a lost race into a failed invoice — the issuance
 * path is documented as degrading rather than throwing. Two accounts issuing
 * on the same day both miss the cache and both fetch; the interleaving that
 * matters is the other one committing before this one writes. The upsert is
 * a single statement, so its ON CONFLICT absorbs that: the loser updates the
 * winner's row with the same day's fixing instead of colliding.
 */
it('survives losing the race to store a fetched CNB rate', function (): void {
    $user = createUser();
    $competitorInserted = false;

    $this->app->bind(CnbRateClientInterface::class, function () use (&$competitorInserted): CnbRateClientInterface {
        return new class(function () use (&$competitorInserted): void {
            systemExchangeRate('EUR', 'CZK', '2026-08-19', '25.100000');
            $competitorInserted = true;
        }) implements CnbRateClientInterface
        {
            public function __construct(private readonly Closure $onFetch) {}

            public function fetchRate(Currency $currency, string $date): float
            {
                ($this->onFetch)();

                return 25.900000;
            }
        };
    });

    $rate = app(ExchangeRateService::class)->getRateOrFetchCnb(Currency::EUR, $user->id, '2026-08-19');

    expect($competitorInserted)->toBeTrue('the race was never actually staged')
        ->and($rate)->toBe(25.9)
        ->and(ExchangeRate::query()->system()->forPair(Currency::EUR, Currency::CZK)->count())->toBe(1);
});
