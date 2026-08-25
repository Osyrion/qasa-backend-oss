<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Services;

use App\Modules\Auth\Application\Contracts\AccountExportContributor;
use App\Modules\Clients\Domain\Models\Client;

/**
 * Clients' section of the GDPR account export.
 *
 * A contributor rather than a query inside Auth's exporter, for the same
 * reason the premium modules are: Auth should not know what a client row
 * looks like, and the module that owns the table is the one that knows which
 * relations belong in the export. Core modules were the last ones still
 * inlined there.
 */
final class ClientsAccountData implements AccountExportContributor
{
    /**
     * @return array<string, mixed>
     */
    public function exportFor(string $ownerId): array
    {
        return [
            'clients' => Client::forUser($ownerId)->with('contactPersons')->get()->toArray(),
        ];
    }
}
