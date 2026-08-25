<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use App\Modules\Clients\Domain\ValueObjects\PortalClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Resolving a client-portal token.
 *
 * Kept off {@see ClientDirectory}: everything there answers a question about a
 * client the *account* already named, under the account scope. This one runs
 * with no account at all — it is how the account gets chosen — and mixing the
 * two would put an unscoped lookup one autocomplete away from the scoped ones.
 */
interface ClientPortalDirectory
{
    /**
     * @throws ModelNotFoundException when the token is unknown or revoked
     */
    public function requireByToken(string $token): PortalClient;
}
