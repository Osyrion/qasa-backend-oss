<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Application\DTOs\ClientData;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Shared\Exceptions\DomainException;
use Throwable;

interface CreateClientActionInterface
{
    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(ClientData $data, User $owner, bool $enforceLimit = true): Client;
}
