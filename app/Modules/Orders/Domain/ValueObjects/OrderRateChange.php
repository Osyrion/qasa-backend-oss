<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;

/**
 * "This order's rate is now that" — everything a history keeps about the
 * change, and nothing else.
 *
 * The account is carried rather than looked up because the recorder writes a
 * tenant-scoped row and may run outside a request; the currency is the
 * order's **own** column, not its effective one, because a null there is the
 * rate saying "whatever the scope is denominated in" and resolving it eagerly
 * would freeze today's answer into history.
 */
final readonly class OrderRateChange
{
    public function __construct(
        public string $orderId,
        public string $ownerId,
        public ?Currency $currency,
        /** Null is a tombstone: the rate was removed, and resolution falls back to the client or global level. */
        public ?float $rate,
    ) {}
}
