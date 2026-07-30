<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Actions;

use App\Modules\Clients\Application\Contracts\CompanyRegistryClientInterface;
use App\Modules\Clients\Application\DTOs\CompanyRegistryData;
use App\Modules\Shared\Exceptions\DomainException;

readonly class FetchCompanyDataAction
{
    /**
     * @param  array<string, CompanyRegistryClientInterface>  $clients  Keyed by ISO country code.
     */
    public function __construct(
        private array $clients,
    ) {}

    /**
     * @throws DomainException
     */
    public function execute(string $country, string $ico): CompanyRegistryData
    {
        $country = strtoupper($country);
        $client = $this->clients[$country] ?? null;

        if ($client === null) {
            throw DomainException::because(
                __('clients.registry_unsupported_country', ['country' => $country])
            );
        }

        $data = $client->fetchByIco($ico);

        if ($data === null) {
            throw DomainException::because(
                __('clients.company_not_found', ['ico' => $ico])
            );
        }

        return $data;
    }
}
