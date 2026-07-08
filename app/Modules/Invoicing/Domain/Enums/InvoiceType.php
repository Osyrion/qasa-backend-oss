<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

enum InvoiceType: string
{
    case Invoice = 'invoice';
    case Proforma = 'proforma';
    case CreditNote = 'credit_note';
    case Storno = 'storno';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Faktura',
            self::Proforma => 'Proforma',
            self::CreditNote => 'Dobropis',
            self::Storno => 'Storno',
        };
    }

    /**
     * Numbering prefix; each type has its own series
     * (distinct prefixes → independent sequences in nextInvoiceNumber()).
     */
    public function numberPrefix(string $userPrefix): string
    {
        return match ($this) {
            self::Invoice => $userPrefix,
            self::Proforma => 'PF',
            self::CreditNote => 'DB',
            self::Storno => 'ST',
        };
    }

    /**
     * Proforma is not a tax document: no DUZP, prints "Není daňový doklad".
     */
    public function isTaxDocument(): bool
    {
        return $this !== self::Proforma;
    }

    /**
     * Corrective documents require a related original invoice.
     */
    public function isCorrective(): bool
    {
        return $this === self::CreditNote || $this === self::Storno;
    }
}
