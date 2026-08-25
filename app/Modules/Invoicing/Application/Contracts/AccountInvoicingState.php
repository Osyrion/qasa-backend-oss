<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

/**
 * How far an account has got with invoicing, for the two places outside
 * Invoicing that have to know.
 *
 * Both callers ask an existence question and act on the answer, never on a
 * document: the onboarding checklist ticks items off, and the profile action
 * refuses to change the account's tax identity once documents carry it. That
 * is why these are three booleans rather than a lookup — handing over a
 * document to answer "is there one" would publish the model to answer a
 * question about a count.
 */
interface AccountInvoicingState
{
    /**
     * Whether the account has issued or received anything at all — an
     * invoice, a quote, or a supplier invoice.
     *
     * The test for "the identity on this account is already printed on
     * paper": IČO, DIČ and residency stop being freely editable once any
     * document carries them, because a document is a frozen record and
     * rewriting the account behind it would rewrite history.
     */
    public function hasAnyDocument(string $ownerId): bool;

    /** Any invoice, drafts included — the onboarding checklist's "first invoice". */
    public function hasInvoice(string $ownerId): bool;

    public function hasBankAccount(string $ownerId): bool;
}
