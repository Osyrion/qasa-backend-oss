<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Services;

use App\Modules\Clients\Application\Contracts\ClientUsageGuardInterface;
use App\Modules\Clients\Application\Contracts\ClientUsagePolicyInterface;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Shared\Exceptions\DomainException;

readonly class ClientUsageGuard implements ClientUsageGuardInterface
{
    public function __construct(
        private ClientUsagePolicyInterface $policy,
    ) {}

    /**
     * @throws DomainException
     */
    public function ensureUsable(Client $client): void
    {
        if (! $this->policy->isUsable($client->usageSubject())) {
            throw DomainException::because(__('clients.locked_readonly'));
        }
    }
}
