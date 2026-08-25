<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

/**
 * The account's own switches over AI, as opposed to what its plan allows.
 *
 * Kept apart from ProvidesPlanEntitlements on purpose: "this account pays for
 * AI extraction" and "this account wants AI extraction" are different
 * questions with different answers, and the extraction decision tree
 * (FieldExtractorFactory) has to ask both. Kept apart from the model for the
 * usual reason — `ai_extraction_enabled` is a column on `users`, and reading
 * it directly is what made Invoicing depend on Auth.
 */
interface ProvidesAiPreferences
{
    /**
     * The account-wide switch for AI invoice extraction (BYOK or platform).
     *
     * The owner's setting, never a team member's own row: it is an account
     * decision, the same way invoiceNumbering() and supplierProfile() are.
     */
    public function aiExtractionEnabled(): bool;
}
