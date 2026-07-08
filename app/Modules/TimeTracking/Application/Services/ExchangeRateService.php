<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Application\Services;

use App\Modules\Shared\Enums\Currency;
use App\Modules\TimeTracking\Application\Contracts\CnbRateClientInterface;
use App\Modules\TimeTracking\Application\Contracts\ExchangeRateServiceInterface;
use App\Modules\TimeTracking\Domain\Enums\ExchangeRateSource;
use App\Modules\TimeTracking\Domain\Models\ExchangeRate;

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

        ExchangeRate::withoutGlobalScope('user')->updateOrCreate(
            [
                'user_id' => null,
                'base_currency' => $base->value,
                'target_currency' => Currency::CZK->value,
                'date' => $date,
            ],
            [
                'rate' => $fetched,
                'source' => ExchangeRateSource::Cnb,
            ],
        );

        return $fetched;
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

        return $rate !== null ? round($amount * $rate, 2) : null;
    }
}
