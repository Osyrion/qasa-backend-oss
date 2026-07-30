<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Shared\Enums\Currency;

interface ExchangeRateServiceInterface
{
    /**
     * Get the effective exchange rate for a currency pair on a given date.
     * Priority: user manual override → system rate → nearest available rate.
     */
    public function getRate(
        Currency $base,
        Currency $target,
        string $userId,
        ?string $date = null,
    ): ?float;

    /**
     * Rate to CZK for invoice issuance: stored rates first (user manual
     * override → system), then an on-demand ČNB fetch cached as a system row.
     * Null when nothing is available — issuance must not fail on this.
     */
    public function getRateOrFetchCnb(Currency $base, string $userId, ?string $date = null): ?float;

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
    ): ?float;

    /**
     * Convert amount from one currency to another.
     */
    public function convert(
        float $amount,
        Currency $from,
        Currency $to,
        string $userId,
        ?string $date = null,
    ): ?float;
}
