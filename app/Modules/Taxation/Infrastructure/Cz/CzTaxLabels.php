<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Cz;

use App\Modules\Shared\Enums\VatStatus;

/**
 * CZ supplier tax-identifier labels — printed on invoices/quotes for a CZ
 * tenant. Client-side labels (any country) are Invoicing's ClientTaxLabelMap.
 */
final class CzTaxLabels
{
    /**
     * @return array<string, string> field name => printed label
     */
    public static function labelsFor(VatStatus $status): array
    {
        return ['ico' => 'IČO', 'dic' => 'DIČ'];
    }
}
