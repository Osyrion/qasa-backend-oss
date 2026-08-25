<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use App\Modules\Clients\Domain\ValueObjects\ClientMatchCriteria;

/**
 * Where an imported client came from.
 *
 * `clients.external_source` / `external_id` are Integrations' columns on a
 * core table — the same arrangement as {@see ClientAutoSendPreference} for
 * Automation. The matching rule lives here too, because it is three queries
 * against columns only this module should be indexing on.
 */
interface ClientImportRegistry
{
    /** The id of the client this row already is, or null for a new one. */
    public function findExisting(string $ownerId, ClientMatchCriteria $criteria): ?string;

    /** Stamp the source onto a client the import has just created. */
    public function link(string $clientId, string $source, ?string $externalId): void;

    /**
     * Stamp the source onto a client the account already had — but only if it
     * carries no source yet, so a second importer never claims a client the
     * first one brought in.
     */
    public function backfill(string $clientId, string $source, ?string $externalId): void;
}
