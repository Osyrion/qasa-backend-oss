<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Clients\Application\Contracts\CreateClientActionInterface;
use App\Modules\Clients\Application\DTOs\ClientData;
use App\Modules\Clients\Domain\Events\ClientCreated;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class CreateClientAction implements CreateClientActionInterface
{
    public function __construct(
        private ClientRepositoryInterface $repository,
    ) {}

    public function create(ClientData $data, Account&ProvidesPlanEntitlements $owner, bool $enforceLimit = true): string
    {
        return $this->execute($data, $owner, $enforceLimit)->id;
    }

    /**
     * $enforceLimit is false only for competitor migration imports — a
     * bulk import must not be truncated mid-way by the free-tier cap; the
     * account is left over its limit and ClientUsageGuard's existing
     * read-only lock takes over from there instead (see
     * docs/plans/COMPETITOR_MIGRATION_IMPORTS_PLAN.md).
     *
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(ClientData $data, Account&ProvidesPlanEntitlements $owner, bool $enforceLimit = true): Client
    {

        $this->validate($data);

        if ($enforceLimit) {
            $this->validateLimit($data, $owner);
        }

        return DB::transaction(function () use ($data, $owner): Client {
            $client = $this->repository->create([
                'user_id' => $owner->accountOwnerId(),
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

            event(new ClientCreated($client));

            return $client;
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
     * withinLimit() checks whether there is room for one more (strictly
     * less than the limit) — pass the count of clients that already exist,
     * not the count after this one is added. max_clients caps the total;
     * max_customers/max_vendors cap each role separately (free tier).
     *
     * @throws DomainException
     */
    private function validateLimit(ClientData $data, Account&ProvidesPlanEntitlements $owner): void
    {
        $count = Client::forUser($owner->accountOwnerId())->active()->count();

        if (! $owner->withinLimit('max_clients', $count)) {
            throw DomainException::because(__('clients.limit_reached'));
        }

        if ($data->is_customer) {
            $customers = Client::forUser($owner->accountOwnerId())->active()->where('is_customer', true)->count();

            if (! $owner->withinLimit('max_customers', $customers)) {
                throw DomainException::because(__('clients.customer_limit_reached'));
            }
        }

        if ($data->is_vendor) {
            $vendors = Client::forUser($owner->accountOwnerId())->active()->where('is_vendor', true)->count();

            if (! $owner->withinLimit('max_vendors', $vendors)) {
                throw DomainException::because(__('clients.vendor_limit_reached'));
            }
        }
    }
}
