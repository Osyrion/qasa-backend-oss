<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

use App\Modules\Shared\Enums\Currency;

/**
 * A tenant's tax residency — the only two supported markets. Immutable once
 * set on User::country (see docs/plans/TAX_RESIDENCY_SEPARATION_PLAN.md).
 */
enum TaxResidency: string
{
    case Sk = 'SK';
    case Cz = 'CZ';

    public function label(): string
    {
        return match ($this) {
            self::Sk => 'Slovensko',
            self::Cz => 'Česko',
        };
    }

    public function vatIdPrefix(): string
    {
        return $this->value;
    }

    /**
     * The currency the personal income tax return is filed in — SK filings
     * are in EUR, CZ filings in CZK, regardless of what currency any given
     * document was issued in (TaxIncomeAggregator converts to this).
     */
    public function returnCurrency(): Currency
    {
        return match ($this) {
            self::Sk => Currency::EUR,
            self::Cz => Currency::CZK,
        };
    }
}
