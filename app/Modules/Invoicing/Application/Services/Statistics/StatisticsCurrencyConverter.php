<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services\Statistics;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Contracts\ExchangeRateServiceInterface;
use App\Modules\Shared\Enums\Currency;

/**
 * Currency conversion for the statistics dashboard. Rows carrying a frozen
 * exchange_rate_snapshot/exchange_rate (CZK pivot) are converted by the
 * aggregators; this class only covers what that cannot: a stored-rate
 * fallback for rows without a snapshot, and the final CZK → user default
 * currency leg. Never calls out to the ČNB (no HTTP from a GET endpoint) —
 * a missing rate degrades to 1.0 rather than failing the request.
 *
 * Rates are memoised, which is why the class is not readonly. A statistics
 * request only ever reads them, and ExchangeRateService caches nothing and
 * costs two queries per call — while the callers ask for the same handful of
 * rates once per month, per partner or per client in their loops.
 */
final class StatisticsCurrencyConverter
{
    /** @var array<string, float> rate to CZK, keyed by currency and account owner */
    private array $ratesToCzk = [];

    public function __construct(
        private readonly ExchangeRateServiceInterface $exchangeRateService,
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

        return $this->ratesToCzk["{$currency->value}:{$userId}"] ??= (
            $this->exchangeRateService->getRate($currency, Currency::CZK, $userId) ?? 1.0
        );
    }

    /**
     * SQL that sums an amount column into CZK, with the two fallback rates
     * resolved up front and returned as bindings.
     *
     * The CASE order is the contract: CZK is taken at face value even when a
     * snapshot exists, a frozen rate beats the live one, and an unrecognised
     * currency passes through at 1:1 — which is what fallbackRateToCzk()
     * returns when no rate is on file. Getting that order wrong silently
     * changes money figures, which is why it lives here rather than being
     * rewritten per aggregator.
     *
     * Resolving the rates once instead of once per row also matters:
     * ExchangeRateService is neither cached nor a singleton, so each lookup
     * costs up to two queries.
     *
     * @param  literal-string  $amountColumn
     * @param  literal-string|null  $frozenRateColumn  null for sources with no frozen rate (Expense)
     * @return array{sql: literal-string, bindings: array{float, float}}
     */
    public function czkSum(string $amountColumn, ?string $frozenRateColumn, string $userId): array
    {
        $frozen = $frozenRateColumn !== null
            ? "WHEN {$frozenRateColumn} IS NOT NULL THEN {$amountColumn} * {$frozenRateColumn}"
            : '';

        return [
            'sql' => "SUM(CASE
                WHEN currency = 'CZK' THEN {$amountColumn}
                {$frozen}
                WHEN currency = 'EUR' THEN {$amountColumn} * ?
                WHEN currency = 'USD' THEN {$amountColumn} * ?
                ELSE {$amountColumn}
            END)",
            'bindings' => [
                $this->fallbackRateToCzk(Currency::EUR, $userId),
                $this->fallbackRateToCzk(Currency::USD, $userId),
            ],
        ];
    }

    /**
     * Convert an amount already expressed in CZK into the user's default
     * currency.
     */
    public function czkToDefault(float $amountCzk, User $user): float
    {
        // Same lookup as the fallback rate, inverted: both ask what one unit
        // of a currency is worth in CZK.
        return $amountCzk / $this->fallbackRateToCzk($user->default_currency, $user->accountOwnerId());
    }
}
