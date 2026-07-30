<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

enum ReverseChargeMode: string
{
    case Domestic = 'domestic';
    case Eu = 'eu';

    /**
     * Lang key (Infrastructure/Lang/{locale}/pdf.php) for the clause printed
     * on the invoice. The domestic clause is country-specific (SK §69 ods.
     * 12 / CZ §92a), so the supplier's own country picks the key.
     */
    public function clauseKey(string $supplierCountry): string
    {
        return match ($this) {
            self::Eu => 'reverse_charge_clause_eu',
            self::Domestic => 'reverse_charge_clause_domestic_'.strtolower($supplierCountry),
        };
    }
}
