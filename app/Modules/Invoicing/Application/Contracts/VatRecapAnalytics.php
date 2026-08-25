<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Enums\ExportPeriodBasis;
use App\Modules\Invoicing\Domain\ValueObjects\CurrencyVatRecap;

/**
 * A period's VAT, summed per currency and rate.
 *
 * The sums come from VatRecapCalculator, the same per-rate bucket maths
 * Invoice::recalculateTotals() and the PDF/CSV exports use, so a tax return's
 * backup reconciles with what accounting already sees. That is precisely why
 * the summing happens here rather than at the caller: reproducing those
 * buckets outside Invoicing — in SQL or in another module's loop — is how the
 * published figures start disagreeing with the documents they came from.
 */
interface VatRecapAnalytics
{
    /**
     * Issued documents (invoice, credit note, storno) in the period, recapped
     * per currency and rate.
     *
     * The basis chooses which date the period is measured against, and on the
     * tax basis a document without a taxable supply date is out of scope
     * rather than dated by its fallback.
     *
     * @return list<CurrencyVatRecap> ascending by rate within each currency
     */
    public function recapByRate(
        string $ownerId,
        ExportPeriodBasis $basis,
        string $from,
        string $to,
        ?string $clientId = null,
    ): array;
}
