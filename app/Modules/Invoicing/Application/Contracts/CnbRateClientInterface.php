<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Shared\Enums\Currency;

interface CnbRateClientInterface
{
    /**
     * CZK per 1 unit of the given currency on the given date
     * (ČNB returns the last published fixing for weekends/holidays).
     * Null when the rate cannot be fetched — callers must degrade gracefully.
     */
    public function fetchRate(Currency $currency, string $date): ?float;
}
