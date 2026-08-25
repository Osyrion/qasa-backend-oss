<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Clients\Application\DTOs\ClientData;
use App\Modules\Clients\Application\Services\ClientUsageGuard;
use App\Modules\Clients\Domain\Events\ClientUpdated;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class UpdateClientAction
{
    public function __construct(
        private ClientRepositoryInterface $repository,
        private ClientUsageGuard $usageGuard,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Client $client, ClientData $data, Account&ProvidesPlanEntitlements $owner): Client
    {
        $this->usageGuard->ensureUsable($client);
        $this->validate($data);
        $this->validateRoleLimits($client, $data, $owner);

        return DB::transaction(function () use ($client, $data): Client {
            $updated = $this->repository->update($client, [
                'client_type' => $data->client_type->value,
                'title' => $data->title,
                'name' => $data->name,
                'surname' => $data->surname,
                'company_name' => $data->company_name,
                'ico' => $data->ico,
                'dic' => $data->dic,
                'vat_id' => $data->vat_id,
                'is_vat_payer' => $data->is_vat_payer,
                'is_customer' => $data->is_customer,
                'is_vendor' => $data->is_vendor,
                'reverse_charge_allowed' => $data->reverse_charge_allowed,
                'email' => $data->email,
                'phone' => $data->phone,
                'address' => $data->address,
                'city' => $data->city,
                'postal_code' => $data->postal_code,
                'country' => $data->country,
                'currency' => $data->currency->value,
                'locale' => $data->locale,
                'color' => $data->color,
                'note' => $data->note,
            ]);

            event(new ClientUpdated($updated));

            return $updated;
        });
    }

    /**
     * @throws DomainException
     */
    private function validate(ClientData $data): void
    {
        if ($data->client_type->requiresPersonName()
            && (empty($data->name) || empty($data->surname))
        ) {
            throw DomainException::because(
                __('clients.name_surname_required', ['client_type' => $data->client_type->label()])
            );
        }

        if ($data->client_type->requiresCompanyName() && empty($data->company_name)) {
            throw DomainException::because(__('clients.company_name_required'));
        }

        if (! $data->is_customer && ! $data->is_vendor) {
            throw DomainException::because(__('clients.role_required'));
        }

        if ($data->reverse_charge_allowed && empty($data->vat_id)) {
            throw DomainException::because(__('clients.reverse_charge_requires_vat_id'));
        }
    }

    /**
     * Enabling a role flag must respect the per-role limits — otherwise
     * flipping is_vendor on an existing customer would bypass them. The
     * count excludes the client being updated.
     *
     * @throws DomainException
     */
    private function validateRoleLimits(Client $client, ClientData $data, Account&ProvidesPlanEntitlements $owner): void
    {
        if ($data->is_customer && ! $client->is_customer) {
            $customers = Client::forUser($owner->accountOwnerId())
                ->active()
                ->where('is_customer', true)
                ->whereKeyNot($client->id)
                ->count();

            if (! $owner->withinLimit('max_customers', $customers)) {
                throw DomainException::because(__('clients.customer_limit_reached'));
            }
        }

        if ($data->is_vendor && ! $client->is_vendor) {
            $vendors = Client::forUser($owner->accountOwnerId())
                ->active()
                ->where('is_vendor', true)
                ->whereKeyNot($client->id)
                ->count();

            if (! $owner->withinLimit('max_vendors', $vendors)) {
                throw DomainException::because(__('clients.vendor_limit_reached'));
            }
        }
    }
}
