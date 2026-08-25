<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Services;

use App\Modules\Clients\Application\Contracts\ClientDirectory;
use App\Modules\Shared\Application\Contracts\LinkableRecordResolver;

/**
 * Clients answering for its own table — through its own directory, so the
 * `clients` query still lives in exactly one place.
 */
final readonly class ClientLinkableRecords implements LinkableRecordResolver
{
    public function __construct(private ClientDirectory $clients) {}

    public function recordTypes(): array
    {
        return ['client'];
    }

    public function existsForAccount(string $recordType, string $recordId, string $ownerId): bool
    {
        return $recordType === 'client'
            && $this->clients->existsForAccount($recordId, $ownerId);
    }
}
