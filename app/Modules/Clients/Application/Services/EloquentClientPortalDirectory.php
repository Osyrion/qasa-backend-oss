<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Services;

use App\Modules\Clients\Application\Contracts\ClientPortalDirectory;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Clients\Domain\ValueObjects\PortalClient;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;

final class EloquentClientPortalDirectory implements ClientPortalDirectory
{
    public function requireByToken(string $token): PortalClient
    {
        /** @var Client $client */
        $client = Client::withoutGlobalScope('user')->where('portal_token', $token)->firstOrFail();

        $owner = $client->user;

        return new PortalClient(
            id: $client->id,
            ownerId: $client->user_id,
            profile: $client->profile(),
            supplier: $owner instanceof ProvidesSupplierProfile ? $owner->supplierProfile() : null,
        );
    }
}
