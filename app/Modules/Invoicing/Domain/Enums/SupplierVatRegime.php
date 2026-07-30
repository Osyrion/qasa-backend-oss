<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

/**
 * VAT treatment of a received (supplier) invoice.
 *
 * `domestic`         — vendor charged domestic VAT normally; nothing to
 *                       self-assess.
 * `eu_reverse_charge` — intra-EU acquisition; the vendor charged 0%, we
 *                       self-assess VAT at our own domestic rate.
 * `import`            — import from outside the EU; VAT is self-assessed at
 *                       whatever rate customs determined, which may not
 *                       match our own catalog.
 */
enum SupplierVatRegime: string
{
    case Domestic = 'domestic';
    case EuReverseCharge = 'eu_reverse_charge';
    case Import = 'import';

    /**
     * Self-assessed regimes owe no VAT to the vendor — it's declared and
     * (where applicable) deducted by us instead of being paid out.
     */
    public function isSelfAssessed(): bool
    {
        return $this === self::EuReverseCharge || $this === self::Import;
    }
}
