<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\ValueObjects\SettledInvoice;

/**
 * How an account's invoices actually got paid.
 */
interface SettlementAnalytics
{
    /**
     * Invoices marked paid whose supply date falls inside the period, each
     * with the date of the last payment recorded against it.
     *
     * Sampled on COALESCE(taxable_supply_at, issued_at), matching
     * Invoicing\Statistics\HealthStatisticsService — the supply date, not the
     * settlement date, is what puts an invoice in a period. An invoice marked
     * paid with no payment row is not returned at all: there is no settlement
     * date to measure against, and counting it as settled on day zero would
     * flatter the figures.
     *
     * @return list<SettledInvoice>
     */
    public function settledInvoices(string $ownerId, string $from, string $to, ?string $clientId = null): array;
}
