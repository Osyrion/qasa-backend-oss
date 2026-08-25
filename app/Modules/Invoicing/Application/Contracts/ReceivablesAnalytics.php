<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\ValueObjects\OutstandingDocument;

/**
 * What an account is still owed, and still owes, as values.
 *
 * The first piece of the analytic read API for Invoicing (the decision in
 * docs/plans/MODULE_BOUNDARY_MODEL_DEBT_PLAN.md, phase 2): methods named after
 * the data need, SQL and knowledge of the `invoices` schema staying here,
 * value objects going out. Reports keeps what it owns — the bucket
 * boundaries, the rankings and the HTTP shape — and two reports that bucket
 * the same documents differently stop each writing their own SQL to do it.
 *
 * Neither method filters on the amount: an aging report counts every open
 * document, a receivables report counts only those still owed something, and
 * baking either choice in here would take that decision away from the caller
 * that owns it.
 */
interface ReceivablesAnalytics
{
    /**
     * Issued invoices not yet settled, with the amount still outstanding.
     *
     * @return list<OutstandingDocument>
     */
    public function openReceivables(string $ownerId, ?string $clientId = null): array;

    /**
     * Received supplier invoices not yet paid. No partial payments are
     * tracked against them, so the whole total falls due.
     *
     * @return list<OutstandingDocument>
     */
    public function openPayables(string $ownerId, ?string $clientId = null): array;
}
