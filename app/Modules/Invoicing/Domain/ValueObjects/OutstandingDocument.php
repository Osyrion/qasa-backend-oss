<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * One open document and the amount still outstanding on it.
 *
 * A third view of an invoice next to InvoiceSummary and PaymentMatchCandidate,
 * and deliberately so: what an aging report needs is who owes, in what
 * currency, how much is left and how late — nothing else, and none of it the
 * document itself.
 *
 * The set that crosses the boundary is safe for the same reason
 * InvoiceLookup::openForMatching()'s is: it is one account's open documents,
 * bounded by how many bills are outstanding, and every consumer buckets or
 * ranks them one at a time rather than asking SQL to sum them.
 */
final readonly class OutstandingDocument
{
    public function __construct(
        public ?string $clientId,
        public Currency $currency,
        /** Null only for a supplier invoice — `invoices.due_at` is NOT NULL. */
        public ?Carbon $dueAt,
        /** Total minus payments recorded, rounded per document. */
        public float $outstanding,
    ) {}

    /**
     * Days past due, negative while still in term. Null when there is no due
     * date at all, which callers treat as "not due" rather than as zero.
     */
    public function daysOverdue(CarbonInterface $asOf): ?int
    {
        if ($this->dueAt === null) {
            return null;
        }

        return (int) $this->dueAt->copy()->startOfDay()->diffInDays($asOf->copy()->startOfDay(), false);
    }
}
