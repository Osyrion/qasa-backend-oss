<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use App\Modules\Clients\Domain\Enums\ClientAbility;
use App\Modules\Shared\Domain\Contracts\Actor;

/**
 * May this person do this to this client?
 *
 * Answering false does not distinguish "not allowed" from "not there" — a
 * caller that needs a 404 first asks {@see ClientDirectory::existsForCurrentAccount()},
 * whose account scope makes a foreign client invisible, and only then asks
 * this. Getting that order wrong turns today's 404 into a 403 that confirms
 * the client exists.
 */
interface ClientAuthorization
{
    public function allows(Actor $actor, ClientAbility $ability, string $clientId): bool;
}
