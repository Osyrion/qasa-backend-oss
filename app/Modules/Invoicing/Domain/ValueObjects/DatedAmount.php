<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;

/**
 * One amount, in the currency it was denominated in, on the day it moved.
 *
 * The unit of a cash-basis read: a payment received, a bill settled, a receipt
 * written. It keeps its date because that is what picks the exchange rate — a
 * year's worth of these cannot be pre-summed per currency without every row
 * silently adopting one day's rate.
 */
final readonly class DatedAmount
{
    /**
     * @param  string  $date  Y-m-d — the day the money moved, not the day the document was raised
     */
    public function __construct(
        public float $amount,
        public Currency $currency,
        public string $date,
    ) {}
}
