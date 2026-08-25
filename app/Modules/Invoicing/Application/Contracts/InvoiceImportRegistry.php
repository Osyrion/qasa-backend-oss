<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Clients\Application\Contracts\ClientImportRegistry;

/**
 * Where an imported invoice came from.
 *
 * `invoices.external_source` / `external_id` are Integrations' columns on our
 * table — the same arrangement {@see ClientImportRegistry}
 * has for clients. The historical number belongs here too: whether a document
 * may carry a number this account's own sequence never issued is a decision
 * about our numbering, not about the file being read.
 */
interface InvoiceImportRegistry
{
    /**
     * The id of the invoice this row has already been imported as, or null.
     */
    public function findImported(string $ownerId, string $source, ?string $externalId): ?string;

    /**
     * Stamps the source onto a document the import has just created, and the
     * number the source issued it under where there is one.
     *
     * The historical number is trusted as-is and never validated against the
     * account's current numbering mask — see "Rozsah importovaných dát" in
     * COMPETITOR_MIGRATION_IMPORTS_PLAN.md. Issuing never reassigns a number
     * that is already set, so this survives the status transitions that follow.
     */
    public function link(string $invoiceId, string $source, ?string $externalId, ?string $issuedNumber): void;
}
