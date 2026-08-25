<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\ValueObjects\ProjectedInflow;

/**
 * What an account's recurring templates are going to invoice next.
 */
interface RecurringInvoiceAnalytics
{
    /**
     * Occurrences of active invoice templates whose payment would fall due
     * inside the window, one entry per occurrence.
     *
     * A template can fire more than once inside a long enough window, and its
     * end date stops it — working out when it fires next means knowing its
     * period, its day-of-month rules and its payment terms, which is why this
     * is answered here and not by the caller.
     *
     * @return list<ProjectedInflow>
     */
    public function projectedInflows(string $ownerId, string $from, string $to): array;
}
