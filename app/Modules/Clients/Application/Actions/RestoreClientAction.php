<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;
use App\Modules\Shared\Exceptions\DomainException;

readonly class RestoreClientAction
{
    public function __construct(
        private ClientRepositoryInterface $repository,
    ) {}

    /**
     * Restoring re-enters the plan's limit counters — archiving and
     * restoring must not be a way to sidestep max_clients/max_customers/
     * max_vendors.
     *
     * @throws DomainException
     */
    public function execute(Client $client, Account&ProvidesPlanEntitlements $owner): Client
    {
        if (! $client->isArchived()) {
            return $client;
        }

        $this->validateLimit($client, $owner);

        return $this->repository->update($client, ['archived_at' => null]);
    }

    /**
     * @throws DomainException
     */
    private function validateLimit(Client $client, Account&ProvidesPlanEntitlements $owner): void
    {
        $count = Client::forUser($owner->accountOwnerId())->active()->count();

        if (! $owner->withinLimit('max_clients', $count)) {
            throw DomainException::because(__('clients.limit_reached'));
        }

        if ($client->is_customer) {
            $customers = Client::forUser($owner->accountOwnerId())->active()->where('is_customer', true)->count();

            if (! $owner->withinLimit('max_customers', $customers)) {
                throw DomainException::because(__('clients.customer_limit_reached'));
            }
        }

        if ($client->is_vendor) {
            $vendors = Client::forUser($owner->accountOwnerId())->active()->where('is_vendor', true)->count();

            if (! $owner->withinLimit('max_vendors', $vendors)) {
                throw DomainException::because(__('clients.vendor_limit_reached'));
            }
        }
    }
}
