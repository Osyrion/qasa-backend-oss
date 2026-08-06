<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

/**
 * Príjmový / výdavkový pokladničný doklad. The direction lives here rather
 * than in the sign of `amount`: a stored negative amount is a value that
 * every sum, every report and every export has to remember to interpret.
 */
enum CashDocumentType: string
{
    case Income = 'income';
    case Expense = 'expense';

    /** Prefix of the document's own number series. */
    public function numberPrefix(): string
    {
        return match ($this) {
            self::Income => 'PPD',
            self::Expense => 'VPD',
        };
    }

    public function opposite(): self
    {
        return $this === self::Income ? self::Expense : self::Income;
    }

    /** +1 for money in, -1 for money out — used by the cash book's balance. */
    public function sign(): int
    {
        return $this === self::Income ? 1 : -1;
    }
}
