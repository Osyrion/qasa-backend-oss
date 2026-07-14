<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

/**
 * Derived from recorded payments vs. invoice total — never stored.
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
    case Overpaid = 'overpaid';

    public static function fromAmounts(float $total, float $paid): self
    {
        return match (true) {
            $paid <= 0.0 => self::Unpaid,
            abs($paid - $total) < 0.005 => self::Paid,
            $paid < $total => self::Partial,
            default => self::Overpaid,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Neuhradená',
            self::Partial => 'Čiastočne uhradená',
            self::Paid => 'Uhradená',
            self::Overpaid => 'Preplatená',
        };
    }
}
