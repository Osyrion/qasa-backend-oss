<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

use App\Modules\Shared\Domain\ValueObjects\PartyProfile;

/**
 * A counterparty that can describe itself for a document.
 *
 * Implemented by the Client model. The mirror image of
 * ProvidesSupplierProfile: one names who issues the document, the other who
 * receives it.
 */
interface ProvidesPartyProfile
{
    public function profile(): PartyProfile;
}
