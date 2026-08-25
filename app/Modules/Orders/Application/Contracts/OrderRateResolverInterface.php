<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Contracts;

use App\Modules\Shared\Domain\Contracts\Account;

/**
 * Loads the rate history for a billing scope in one query, so many time
 * entries can be priced without a query each.
 *
 * OSS binds NoRateHistoryResolver; the Pricing module rebinds this to its
 * RateResolver via bootstrap provider ordering (Orders registers before
 * Pricing).
 *
 * Scoped by ids. The history is keyed on `client_id`/`order_id` and nothing
 * else about either record is read, so naming the models here would have made
 * this contract — and every caller of it — depend on Clients' aggregate for
 * two string columns.
 */
interface OrderRateResolverInterface
{
    public function sheetFor(Account $user, ?string $clientId = null, ?string $orderId = null): OrderRateSheetInterface;
}
