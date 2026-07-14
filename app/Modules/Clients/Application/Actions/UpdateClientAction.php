<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Clients\Application\DTOs\ClientData;
use App\Modules\Clients\Domain\Events\ClientUpdated;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class UpdateClientAction
{
    public function __construct(
        private ClientRepositoryInterface $repository,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Client $client, ClientData $data): Client
    {
        $this->validate($data);

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
                'reverse_charge_allowed' => $data->reverse_charge_allowed,
                'is_customer' => $data->is_customer,
                'is_vendor' => $data->is_vendor,
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
}
