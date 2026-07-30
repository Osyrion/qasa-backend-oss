<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

/**
 * Supplier tax status. Drives VAT charging, reverse-charge eligibility and
 * PDF rendering (App\Modules\Invoicing\Application\Services\InvoicePdfService).
 *
 * `non_payer`  — not registered for VAT, never charges VAT.
 * `identified` — "identified person" (SK: identifikovaná osoba / CZ: identifikovaná
 *                osoba) — has a VAT ID for intra-EU acquisitions but cannot
 *                charge domestic VAT and has no right of deduction.
 * `payer`      — full VAT payer, charges VAT and has right of deduction.
 */
enum VatStatus: string
{
    case NonPayer = 'non_payer';
    case Identified = 'identified';
    case Payer = 'payer';

    public function isVatPayer(): bool
    {
        return $this === self::Payer;
    }

    /**
     * Only a full payer may charge (domestic) VAT on its invoices.
     */
    public function canChargeVat(): bool
    {
        return $this === self::Payer;
    }

    /**
     * Both a full payer and an identified person hold a VAT ID and print it.
     */
    public function hasVatId(): bool
    {
        return $this === self::Payer || $this === self::Identified;
    }

    public static function fromLegacyBool(bool $isVatPayer): self
    {
        return $isVatPayer ? self::Payer : self::NonPayer;
    }
}
