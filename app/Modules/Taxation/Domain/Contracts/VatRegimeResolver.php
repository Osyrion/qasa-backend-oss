<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Contracts;

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Services\InvoiceVatRegimeDecision;
use App\Modules\Shared\Enums\VatStatus;
use App\Modules\Shared\Exceptions\DomainException;

/**
 * Decides whether an invoice is reverse-charged, and in which mode, from the
 * supplier's own VAT status and the client — implementations already know
 * their own country (no supplier-country parameter needed).
 */
interface VatRegimeResolver
{
    /**
     * @throws DomainException
     */
    public function resolve(
        VatStatus $supplierStatus,
        Client $client,
        bool $requestReverseCharge,
    ): InvoiceVatRegimeDecision;
}
