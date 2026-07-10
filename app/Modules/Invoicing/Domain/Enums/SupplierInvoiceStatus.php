<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

enum SupplierInvoiceStatus: string
{
    case Draft = 'draft';
    case Received = 'received';
    case Booked = 'booked';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Koncept',
            self::Received => 'Prijatá',
            self::Booked => 'Zaúčtovaná',
            self::Paid => 'Uhradená',
            self::Cancelled => 'Stornovaná',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Cancelled], true);
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => $next === self::Received,
            self::Received => in_array($next, [self::Booked, self::Paid, self::Cancelled], true),
            self::Booked => in_array($next, [self::Paid, self::Cancelled], true),
            self::Paid => false,
            self::Cancelled => false,
        };
    }
}
