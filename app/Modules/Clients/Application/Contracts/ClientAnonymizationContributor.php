<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

/**
 * Lets a module clear its own columns when a client is anonymised, without
 * Clients knowing they exist. Implementations are tagged 'client.anonymize'
 * in their own service provider.
 *
 * clients is a core table, but several modules hang a nullable column off it
 * from their own migration — bank_iban (Banking), external_source/external_id
 * (Integrations), auto_send_invoices (Automation). Those columns are simply
 * **not there** in the generated OSS core, so a core action that names one
 * fails with SQLSTATE 42703 the moment the module is stripped. Static
 * analysis cannot see it; only the generator can, and it did.
 */
interface ClientAnonymizationContributor
{
    /**
     * Clear this module's identifying columns on the client. Runs inside the
     * anonymisation transaction, after the core columns are cleared.
     */
    public function anonymize(string $clientId): void;
}
