<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\ValueObjects\PublicInvoice;

/**
 * Reading an invoice on the online-payment path.
 *
 * Kept off {@see InvoiceLookup} for the reason `ClientPortalDirectory` is kept
 * off `ClientDirectory`: everything there answers a question about a document
 * the *account* already named, under the account scope. These run with no
 * account — a public token is how the account gets chosen, and a Stripe
 * webhook names it explicitly because it arrives with nobody authenticated.
 * Mixing the two would put an unscoped lookup one autocomplete away from the
 * scoped ones.
 */
interface PublicInvoiceLookup
{
    /**
     * The document a public link points at, or null when the token is unknown
     * or the link has been switched off.
     */
    public function byPublicToken(string $publicToken): ?PublicInvoice;

    /**
     * By id, naming the account explicitly — for a caller with no
     * authenticated request. Null when that account does not own it, which is
     * the cross-tenant check the caller would otherwise write by hand.
     */
    public function forAccount(string $invoiceId, string $ownerId): ?PublicInvoice;
}
