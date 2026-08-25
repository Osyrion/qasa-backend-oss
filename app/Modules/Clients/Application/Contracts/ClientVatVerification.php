<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use App\Modules\Clients\Domain\ValueObjects\ClientVatStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A client's VAT standing, and the stamp a successful VIES check leaves on it.
 *
 * The *rule* — that an intra-EU reverse-charge invoice may only be issued to a
 * verified number, with a grace window while VIES is unreachable — is
 * Invoicing's and stays there. Which column records the check, and how it is
 * written, is ours.
 */
interface ClientVatVerification
{
    /**
     * @throws ModelNotFoundException when $ownerId does not own $clientId
     */
    public function requireStatus(string $clientId, string $ownerId): ClientVatStatus;

    /** Stamp a successful VIES check against the client. */
    public function markVerified(string $clientId, string $ownerId): void;
}
