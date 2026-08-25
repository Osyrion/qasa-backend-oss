<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use App\Modules\Clients\Domain\Enums\ClientRole;

/**
 * Which clients a plan limit keeps usable when an account is over it.
 *
 * Deliberately not part of {@see ClientDirectory}: the directory resolves the
 * usage guard, the guard resolves the policy, and the policy asks this — put
 * the query on the directory and the container recurses on itself. This is the
 * one thing a usage policy needs from the `clients` table, so it is the whole
 * contract, and its implementation depends on nothing.
 */
interface ClientUsageRoster
{
    /**
     * The oldest active clients holding this role, oldest first.
     *
     * Ordered by `created_at` then `id`, because "the oldest N stay usable"
     * has to be a stable answer: two clients created in the same second must
     * not swap places between requests and lock a different one each time.
     *
     * @return list<string> client ids, at most $limit of them
     */
    public function oldestActiveIds(string $ownerId, ClientRole $role, int $limit): array;
}
