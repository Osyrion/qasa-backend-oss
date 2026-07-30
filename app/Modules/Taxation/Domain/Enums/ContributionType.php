<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

/**
 * social/health — sociálne a zdravotné odvody (SK) / sociální a zdravotní
 * pojištění (CZ). income_tax_advance — zaplatená záloha na daň z príjmu,
 * zúčtovaná v ročnom priznaní.
 */
enum ContributionType: string
{
    case Social = 'social';
    case Health = 'health';
    case IncomeTaxAdvance = 'income_tax_advance';
}
