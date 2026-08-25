<?php

declare(strict_types=1);

namespace App\Modules\Clients\Domain\ValueObjects;

use App\Modules\Clients\Domain\Enums\ClientRole;

/**
 * A client as a plan limit sees it: an id, an account, and which roles it
 * holds.
 *
 * The whole input to "may this client still be used". `PartyProfile` would be
 * the wrong value here — it is who the client *is*, printed on a document,
 * and carries none of the three things this question turns on.
 */
final readonly class ClientUsageSubject
{
    public function __construct(
        public string $id,
        public string $ownerId,
        public bool $isCustomer,
        public bool $isVendor,
    ) {}

    public function holds(ClientRole $role): bool
    {
        return match ($role) {
            ClientRole::Customer => $this->isCustomer,
            ClientRole::Vendor => $this->isVendor,
        };
    }
}
