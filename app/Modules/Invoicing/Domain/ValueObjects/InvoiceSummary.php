<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;

/**
 * An issued document as another module is allowed to read it.
 *
 * Eight modules read invoices and almost none of them write: Reports
 * aggregates, Banking matches payments, Automation reminds, Integrations
 * dispatches. Taking the `Invoice` model to do that publishes Invoicing's
 * database rather than an API — the consumer type-hints the aggregate, walks
 * its relations, and from then on a column cannot be renamed without breaking
 * code Invoicing does not own. This is the same move `SupplierProfile` made
 * for the account.
 *
 * **Deliberately only the fields something reads today.** A summary that
 * mirrors the table is the model again with extra steps; when a consumer needs
 * more, the field is added here on purpose — that conversation is the point of
 * the boundary, and `docs/plans/MODULE_BOUNDARY_MODEL_DEBT_PLAN.md` phase 2
 * says so explicitly.
 *
 * Not for aggregation. A reporting service summing thousands of invoices wants
 * SQL, not a collection of these — see the note in that plan about why the
 * Reports module cannot be converted this way.
 */
final readonly class InvoiceSummary
{
    public function __construct(
        public string $id,
        /** Null until the document is issued; drafts have no number yet. */
        public ?string $number,
        /** The name frozen onto the document at issue, or the client's today. */
        public string $clientName,
        public Currency $currency,
        public float $total,
        public Carbon $dueAt,
        /** How many reminders have already gone out for this document. */
        public int $reminderCount,
    ) {}
}
