<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Shared\Exceptions\DomainException;

interface ClientUsageGuardInterface
{
    /**
     * @throws DomainException when the client is read-only (exceeds the
     *                         account plan limits)
     */
    public function ensureUsable(Client $client): void;
}
