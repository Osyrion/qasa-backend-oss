<?php

declare(strict_types=1);

namespace App\Modules\Clients\Domain\ValueObjects;

use App\Modules\Shared\Domain\ValueObjects\PartyProfile;
use App\Modules\Shared\Domain\ValueObjects\SupplierProfile;

/**
 * Who is on the other end of a client-portal link.
 *
 * The portal is unauthenticated and identifies its visitor by a token alone,
 * so the module serving it has to resolve a client without an account scope to
 * lean on. That resolution is ours; everything the page then prints is here.
 *
 * The supplier comes along because the portal shows "who is billing you" and
 * the answer is simply the account owning the client — one lookup, not two.
 */
final readonly class PortalClient
{
    public function __construct(
        public string $id,
        /** The account the client belongs to. */
        public string $ownerId,
        public PartyProfile $profile,
        /** Null only where the owning account has since been deleted. */
        public ?SupplierProfile $supplier,
    ) {}
}
