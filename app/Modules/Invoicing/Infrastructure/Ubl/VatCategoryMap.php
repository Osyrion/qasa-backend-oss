<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ubl;

use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;
use App\Modules\Invoicing\Domain\Models\Invoice;

/**
 * EN 16931 BT-118 — the VAT category code (UNCL 5305) an invoice's tax
 * subtotals carry.
 *
 * The single place in the UBL export where a wrong answer is a tax error
 * rather than a formatting one, so the whole decision lives here with the
 * table spelled out, instead of being scattered through the builder:
 *
 *   reverse charge, domestic  → AE  (prenesenie daňovej povinnosti)
 *   reverse charge, EU        → K   (intra-community supply)
 *   supplier not VAT-registered → E (exempt)
 *   rate 0, none of the above → Z   (zero rated)
 *   rate above 0             → S   (standard/reduced)
 *
 * Note that "reduced rate" is not a category of its own in UNCL 5305 — a
 * 10 % Slovak rate is category S with Percent 10, which is the mistake worth
 * naming because every domestic form treats it as a separate rate.
 */
final class VatCategoryMap
{
    public const STANDARD = 'S';

    public const ZERO = 'Z';

    public const EXEMPT = 'E';

    public const REVERSE_CHARGE = 'AE';

    public const INTRA_COMMUNITY = 'K';

    public static function categoryFor(Invoice $invoice, float $rate): string
    {
        if ($invoice->reverse_charge) {
            return $invoice->reverse_charge_mode === ReverseChargeMode::Eu
                ? self::INTRA_COMMUNITY
                : self::REVERSE_CHARGE;
        }

        if (! self::supplierIsVatRegistered($invoice)) {
            return self::EXEMPT;
        }

        return $rate > 0.0 ? self::STANDARD : self::ZERO;
    }

    /**
     * Every category but S needs a stated reason (BT-120).
     */
    public static function exemptionReasonFor(string $category): ?string
    {
        $key = match ($category) {
            self::REVERSE_CHARGE => 'invoicing.ubl_exemption_reverse_charge',
            self::INTRA_COMMUNITY => 'invoicing.ubl_exemption_intra_community',
            self::EXEMPT => 'invoicing.ubl_exemption_not_registered',
            self::ZERO => 'invoicing.ubl_exemption_zero_rated',
            default => null,
        };

        return $key === null ? null : (string) __($key);
    }

    private static function supplierIsVatRegistered(Invoice $invoice): bool
    {
        $snapshot = $invoice->supplier_snapshot ?? [];

        return (bool) ($snapshot['is_vat_payer'] ?? false);
    }
}
