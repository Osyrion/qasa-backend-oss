<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;

/**
 * One paid invoice reduced to the three dates a payment-behaviour figure is
 * computed from, plus what it was worth.
 *
 * Rows rather than sums, unlike {@see MonthlyTurnover}, and for a stated
 * reason: days-to-pay and days-late are Carbon differences, and reproducing
 * Carbon's day arithmetic in SQL would silently shift published figures away
 * from what Invoicing\Statistics\HealthStatisticsService reports for the same
 * invoices. The set is one account's invoices settled inside a caller-chosen
 * period, and the caller averages them one at a time.
 */
final readonly class SettledInvoice
{
    public function __construct(
        public string $clientId,
        public Currency $currency,
        public float $total,
        public Carbon $issuedAt,
        public Carbon $dueAt,
        /** The last payment recorded against the invoice. */
        public Carbon $settledAt,
    ) {}
}
