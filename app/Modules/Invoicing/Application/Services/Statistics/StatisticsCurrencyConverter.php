<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services\Statistics;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Enums\Currency;
use App\Modules\TimeTracking\Application\Contracts\ExchangeRateServiceInterface;

/**
 * Currency conversion for the statistics dashboard. Rows carrying a frozen
 * exchange_rate_snapshot/exchange_rate (CZK pivot) are converted in SQL by
 * the aggregators; this class only covers what SQL cannot: a stored-rate
 * fallback for rows without a snapshot, and the final CZK → user default
 * currency leg. Never calls out to the ČNB (no HTTP from a GET endpoint) —
 * a missing rate degrades to 1.0 rather than failing the request.
 */
final readonly class StatisticsCurrencyConverter
{
    public function __construct(
        private ExchangeRateServiceInterface $exchangeRateService,
    ) {}

    /**
     * Rate to CZK for a currency with no frozen snapshot, from stored rates
     * only. Defaults to 1.0 (never blocks the endpoint) when nothing is on
     * file.
     */
    public function fallbackRateToCzk(Currency $currency, string $userId): float
    {
        if ($currency === Currency::CZK) {
            return 1.0;
        }

        return $this->exchangeRateService->getRate($currency, Currency::CZK, $userId) ?? 1.0;
    }

    /**
     * Convert an amount already expressed in CZK into the user's default
     * currency.
     */
    public function czkToDefault(float $amountCzk, User $user): float
    {
        $default = $user->default_currency;

        if ($default === Currency::CZK) {
            return $amountCzk;
        }

        $rate = $this->exchangeRateService->getRate($default, Currency::CZK, $user->accountOwnerId()) ?? 1.0;

        return $amountCzk / $rate;
    }
}
