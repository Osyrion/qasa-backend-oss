<?php

declare(strict_types=1);

namespace App\Modules\Clients\Presentation\Support;

use App\Modules\Clients\Application\Contracts\ClientRepresentation;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Clients\Presentation\Resources\ClientResource;
use App\Modules\Shared\Domain\Contracts\ProvidesPartyProfile;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Renders the client through the resource Clients' own endpoints use, so an
 * embedded counterparty and a directly requested one cannot say different
 * things.
 */
final readonly class ClientResourceRepresentation implements ClientRepresentation
{
    public function forClient(ProvidesPartyProfile&Model $client): array
    {
        if (! $client instanceof Client) {
            throw new LogicException('Expected a client row, got '.$client::class);
        }

        return ClientResource::make($client)->resolve();
    }
}
