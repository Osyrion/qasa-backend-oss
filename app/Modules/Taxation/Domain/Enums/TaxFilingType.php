<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

/**
 * `VatReturn` is generatable for both SK (SkVatReturnService) and CZ
 * (CzVatReturnService) — best-effort row mapping against a verified XSD in
 * each case, see DphXmlBuilder/DphDp3XmlBuilder's own caveats.
 */
enum TaxFilingType: string
{
    case ControlStatement = 'control_statement';
    case EuSalesList = 'eu_sales_list';
    case IncomeTax = 'income_tax';
    case VatReturn = 'vat_return';
}
