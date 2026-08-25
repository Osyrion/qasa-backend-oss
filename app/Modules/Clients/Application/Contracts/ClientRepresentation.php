<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use App\Modules\Shared\Domain\Contracts\ProvidesPartyProfile;
use Illuminate\Database\Eloquent\Model;

/**
 * The canonical JSON body for a client — the `#/components/schemas/Client`
 * shape, as returned by the clients endpoints.
 *
 * Six document resources embed the counterparty: invoices, quotes, supplier
 * invoices, recurring templates, inbox items and orders. All six were reaching
 * into `Clients\Presentation\Resources\ClientResource` to do it, which ties
 * two modules' API shapes together — a field added for the clients screen
 * silently lands inside every document too, and Clients cannot change its own
 * response without changing theirs.
 *
 * The shape is the contract, the resource is one implementation of it — same
 * arrangement as Auth's AccountRepresentation, and the reason this interface
 * lives in Application while what satisfies it lives in Presentation.
 *
 * The parameter is the party contract rather than the model: a caller holding
 * a document's `client` relation can pass it without naming Clients' model,
 * which is the other half of what this closes.
 */
interface ClientRepresentation
{
    /**
     * @return array<string, mixed>
     */
    public function forClient(ProvidesPartyProfile&Model $client): array;
}
