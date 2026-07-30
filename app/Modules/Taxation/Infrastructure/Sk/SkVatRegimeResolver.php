<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Sk;

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;
use App\Modules\Invoicing\Domain\Services\InvoiceVatRegimeDecision;
use App\Modules\Shared\Enums\VatStatus;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Domain\Contracts\VatRegimeResolver;

/**
 * SK reverse-charge regime decision. Copy of CzVatRegimeResolver with SK
 * hardcoded — divergence (SK §7/§7a identified-person specifics) stays
 * local to this class, see docs/plans/TAX_RESIDENCY_PHASE_2_TAXATION_MODULE.md.
 *
 * - non_payer: never RC.
 * - identified: auto RC "eu" for an EU client with a VAT ID; otherwise never.
 * - payer: auto RC "eu" for an EU client with a VAT ID; RC "domestic" only
 *   when requested AND the client has reverse_charge_allowed.
 */
final class SkVatRegimeResolver implements VatRegimeResolver
{
    private const string COUNTRY = 'SK';

    /**
     * @throws DomainException
     */
    public function resolve(
        VatStatus $supplierStatus,
        Client $client,
        bool $requestReverseCharge,
    ): InvoiceVatRegimeDecision {
        if ($supplierStatus === VatStatus::NonPayer) {
            if ($requestReverseCharge) {
                throw DomainException::because(__('invoicing.reverse_charge_requires_vat_status'));
            }

            return new InvoiceVatRegimeDecision(false, null);
        }

        if ($this->isEuClientWithVatId($client)) {
            return new InvoiceVatRegimeDecision(true, ReverseChargeMode::Eu);
        }

        if ($supplierStatus === VatStatus::Identified) {
            return new InvoiceVatRegimeDecision(false, null);
        }

        // Payer, domestic reverse charge — only on request and only when the
        // client has opted in.
        if ($requestReverseCharge) {
            $isDomestic = strtoupper($client->country) === self::COUNTRY;

            if ($isDomestic && $client->reverse_charge_allowed) {
                return new InvoiceVatRegimeDecision(true, ReverseChargeMode::Domestic);
            }

            throw DomainException::because(__('invoicing.reverse_charge_not_allowed_for_client'));
        }

        return new InvoiceVatRegimeDecision(false, null);
    }

    private function isEuClientWithVatId(Client $client): bool
    {
        if ($client->vat_id === null || $client->vat_id === '') {
            return false;
        }

        /** @var list<string> $euMembers */
        $euMembers = config('countries.eu_members', []);
        $clientCountry = strtoupper($client->country);

        return $clientCountry !== self::COUNTRY && in_array($clientCountry, $euMembers, true);
    }
}
