<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use App\Modules\Clients\Application\DTOs\ClientData;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;
use App\Modules\Shared\Exceptions\DomainException;
use Throwable;

/**
 * Creating a client from outside Clients.
 *
 * Returns the new client's id rather than the aggregate: a module that holds
 * the model has published the `clients` table, not an API. Clients' own
 * controller keeps calling `execute()` on the concrete action, because it does
 * legitimately hold the model and re-reading the row to render it would be a
 * query bought for nothing.
 */
interface CreateClientActionInterface
{
    /**
     * @return string the new client's id
     *
     * @throws DomainException
     * @throws Throwable
     */
    public function create(ClientData $data, Account&ProvidesPlanEntitlements $owner, bool $enforceLimit = true): string;
}
