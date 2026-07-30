<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Sk;

use App\Modules\Shared\Enums\VatStatus;

/**
 * SK supplier tax-identifier labels — printed on invoices/quotes for a SK
 * tenant. Client-side labels (any country) are Invoicing's ClientTaxLabelMap.
 */
final class SkTaxLabels
{
    /**
     * @return array<string, string> field name => printed label
     */
    public static function labelsFor(VatStatus $status): array
    {
        // SK prints IČ DPH for both a full payer and an identified person —
        // an identified person is assigned a VAT ID too, just without the
        // right to charge domestic VAT.
        return $status->hasVatId()
            ? ['ico' => 'IČO', 'dic' => 'DIČ', 'vat_id' => 'IČ DPH']
            : ['ico' => 'IČO', 'dic' => 'DIČ'];
    }
}
