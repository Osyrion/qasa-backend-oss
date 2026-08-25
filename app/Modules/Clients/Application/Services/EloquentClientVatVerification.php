<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Services;

use App\Modules\Clients\Application\Contracts\ClientVatVerification;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Clients\Domain\ValueObjects\ClientVatStatus;

final class EloquentClientVatVerification implements ClientVatVerification
{
    public function requireStatus(string $clientId, string $ownerId): ClientVatStatus
    {
        /** @var Client $client */
        $client = Client::forUser($ownerId)->findOrFail($clientId);

        return new ClientVatStatus(
            country: $client->country,
            vatId: $client->vat_id,
            verifiedAt: $client->vat_verified_at?->toImmutable(),
        );
    }

    public function markVerified(string $clientId, string $ownerId): void
    {
        /** @var Client $client */
        $client = Client::forUser($ownerId)->findOrFail($clientId);

        $client->forceFill(['vat_verified_at' => now()])->save();
    }
}
