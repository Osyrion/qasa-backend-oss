<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Services\InvoiceNumberMask;

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
     * The user's numbering mask for this document type, with a type prefix
     * ("PF-", "DB-", "ST-") inserted before it so each type gets its own
     * sequence. Falls back to the legacy "{prefix}-{YYYY}-{NNN}" format when
     * the user has not configured a mask, keeping existing output identical.
     */
    public function numberMask(User $user): InvoiceNumberMask
    {
        $mask = $user->accountOwner()->invoice_number_mask;

        if ($mask === null || $mask === '') {
            return new InvoiceNumberMask(
                $this->numberPrefix($user->accountOwner()->invoice_prefix).'-{YYYY}-{NNN}'
            );
        }

        return new InvoiceNumberMask(match ($this) {
            self::Invoice => $mask,
            self::Proforma => 'PF-'.$mask,
            self::CreditNote => 'DB-'.$mask,
            self::Storno => 'ST-'.$mask,
        });
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
