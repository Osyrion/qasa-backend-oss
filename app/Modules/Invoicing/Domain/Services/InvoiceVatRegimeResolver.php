<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;
use App\Modules\Shared\Enums\VatStatus;
use App\Modules\Shared\Exceptions\DomainException;

/**
 * Single place that decides whether an invoice is reverse-charged, and in
 * which mode, from the supplier's VAT status and the client:
 *
 * - non_payer: never RC.
 * - identified: auto RC "eu" for an EU client with a VAT ID; otherwise never
 *   (domestic/non-EU identified-person supplies carry no VAT and no RC).
 * - payer: auto RC "eu" for an EU client with a VAT ID; RC "domestic" only
 *   when requested AND the client has reverse_charge_allowed.
 */
final class InvoiceVatRegimeResolver
{
    /**
     * @throws DomainException
     */
    public function resolve(
        VatStatus $supplierStatus,
        string $supplierCountry,
        Client $client,
        bool $requestReverseCharge,
    ): InvoiceVatRegimeDecision {
        if ($supplierStatus === VatStatus::NonPayer) {
            if ($requestReverseCharge) {
                throw DomainException::because(__('invoicing.reverse_charge_requires_vat_status'));
            }

            return new InvoiceVatRegimeDecision(false, null);
        }

        if ($this->isEuClientWithVatId($supplierCountry, $client)) {
            return new InvoiceVatRegimeDecision(true, ReverseChargeMode::Eu);
        }

        if ($supplierStatus === VatStatus::Identified) {
            return new InvoiceVatRegimeDecision(false, null);
        }

        // Payer, domestic reverse charge — only on request and only when the
        // client has opted in.
        if ($requestReverseCharge) {
            $isDomestic = strtoupper($client->country) === strtoupper($supplierCountry);

            if ($isDomestic && $client->reverse_charge_allowed) {
                return new InvoiceVatRegimeDecision(true, ReverseChargeMode::Domestic);
            }

            throw DomainException::because(__('invoicing.reverse_charge_not_allowed_for_client'));
        }

        return new InvoiceVatRegimeDecision(false, null);
    }

    private function isEuClientWithVatId(string $supplierCountry, Client $client): bool
    {
        if ($client->vat_id === null || $client->vat_id === '') {
            return false;
        }

        /** @var list<string> $euMembers */
        $euMembers = config('countries.eu_members', []);
        $clientCountry = strtoupper($client->country);

        return $clientCountry !== strtoupper($supplierCountry) && in_array($clientCountry, $euMembers, true);
    }
}
