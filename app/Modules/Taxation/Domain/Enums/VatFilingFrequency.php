<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

/**
 * How often a VAT payer files a return with their tax authority — set once
 * the account knows its registration terms (not derived automatically; SK/CZ
 * assign this at VAT registration based on turnover/request, not something
 * this system can infer). Drives which period a TaxFiling snapshot covers
 * and, for the reminder command, which period's deadline to warn about.
 */
enum VatFilingFrequency: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
}
