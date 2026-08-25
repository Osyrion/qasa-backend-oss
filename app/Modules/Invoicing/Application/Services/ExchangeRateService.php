<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\CnbRateClientInterface;
use App\Modules\Invoicing\Application\Contracts\ExchangeRateServiceInterface;
use App\Modules\Invoicing\Domain\Enums\ExchangeRateSource;
use App\Modules\Invoicing\Domain\Models\ExchangeRate;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Support\Decimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class ExchangeRateService implements ExchangeRateServiceInterface
{
    public function __construct(
        private readonly CnbRateClientInterface $cnbClient,
    ) {}

    /**
     * Get the effective exchange rate for a currency pair on a given date.
     * Priority: user manual override → system rate → nearest available rate.
     */
    public function getRate(
        Currency $base,
        Currency $target,
        string $userId,
        ?string $date = null,
    ): ?float {
        if ($base === $target) {
            return 1.0;
        }

        $date ??= now()->toDateString();

        // 1. User manual override for this date
        $userRate = ExchangeRate::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('base_currency', $base->value)
            ->where('target_currency', $target->value)
            ->where('date', $date)
            ->value('rate');

        if ($userRate !== null) {
            return (float) $userRate;
        }

        // 2. System rate for this date
        $systemRate = ExchangeRate::withoutGlobalScope('user')
            ->whereNull('user_id')
            ->where('base_currency', $base->value)
            ->where('target_currency', $target->value)
            ->where('date', '<=', $date)
            ->orderBy('date', 'desc')
            ->value('rate');

        return $systemRate !== null ? (float) $systemRate : null;
    }

    /**
     * Rate to CZK for invoice issuance: stored rates first (user manual
     * override → system), then an on-demand ČNB fetch cached as a system row.
     * Null when nothing is available — issuance must not fail on this.
     */
    public function getRateOrFetchCnb(Currency $base, string $userId, ?string $date = null): ?float
    {
        if ($base === Currency::CZK) {
            return 1.0;
        }

        $date ??= now()->toDateString();

        // Stored rate for the exact date (user override wins over system)
        $exact = ExchangeRate::withoutGlobalScope('user')
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)->orWhereNull('user_id');
            })
            ->where('base_currency', $base->value)
            ->where('target_currency', Currency::CZK->value)
            ->whereDate('date', $date)
            ->orderByRaw('user_id IS NULL')
            ->value('rate');

        if ($exact !== null) {
            return (float) $exact;
        }

        $fetched = $this->cnbClient->fetchRate($base, $date);

        if ($fetched === null) {
            // Last resort: nearest prior stored rate
            return $this->getRate($base, Currency::CZK, $userId, $date);
        }

        $this->storeSystemRate($base, Currency::CZK, $date, $fetched);

        return $fetched;
    }

    /**
     * Store a system (account-less) rate.
     *
     * Not an Eloquent write: `exchange_rates` mixes per-account rows with
     * shared ones, and since
     * 2026_08_19_000002_restrict_system_exchange_rate_writes the RLS policy
     * lets a tenant connection *read* the shared rows but not write them —
     * the global ČNB fixing is what every account's foreign-currency invoice
     * is converted with, and no tenant request has any business overwriting
     * it. upsert_system_exchange_rate() is the one narrow way through: a
     * SECURITY DEFINER function that writes this row shape and nothing else.
     *
     * The upsert also settles the race it replaced. Two accounts issuing on
     * the same day both miss the cache and both arrive here;
     * unique_system_rate_per_day makes the second one an ON CONFLICT update
     * of the first, so the race costs a redundant fetch, never a failed
     * issuance or a duplicate row. Both wrote the same day's fixing anyway.
     */
    private function storeSystemRate(Currency $base, Currency $target, string $date, float $rate): void
    {
        DB::select('SELECT public.upsert_system_exchange_rate(?, ?, ?, ?, ?, ?)', [
            (new ExchangeRate)->newUniqueId(),
            $base->value,
            $target->value,
            $date,
            (string) Decimal::of($rate)->toScale(6, RoundingMode::HalfUp),
            ExchangeRateSource::Cnb->value,
        ]);
    }

    /**
     * Cross rate between two arbitrary currencies via CZK, for invoice
     * generation: null when either leg's rate is unavailable, since a
     * missing rate here must block generation rather than silently skip
     * the conversion (unlike getRateOrFetchCnb's issuance-time behavior).
     */
    public function getConversionRateOrFetchCnb(
        Currency $from,
        Currency $to,
        string $userId,
        ?string $date = null,
    ): ?float {
        if ($from === $to) {
            return 1.0;
        }

        $fromToCzk = $this->getRateOrFetchCnb($from, $userId, $date);
        $toToCzk = $this->getRateOrFetchCnb($to, $userId, $date);

        if ($fromToCzk === null || $toToCzk === null) {
            return null;
        }

        return round($fromToCzk / $toToCzk, 6);
    }

    /**
     * Convert amount from one currency to another.
     */
    public function convert(
        float $amount,
        Currency $from,
        Currency $to,
        string $userId,
        ?string $date = null,
    ): ?float {
        $rate = $this->getRate($from, $to, $userId, $date);

        return $rate !== null
            ? (float) Decimal::money(Decimal::of($amount)->multipliedBy(Decimal::of($rate)))
            : null;
    }
}
