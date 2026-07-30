<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Sent = 'sent';
    case Reminded = 'reminded';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Credited = 'credited';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Koncept',
            self::Issued => 'Vystavená',
            self::Sent => 'Odoslaná',
            self::Reminded => 'Upomenutá',
            self::Paid => 'Zaplatená',
            self::Cancelled => 'Stornovaná',
            self::Credited => 'Dobropisovaná',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Cancelled, self::Credited], true);
    }

    /**
     * Statuses of open receivables — issued to the client, not yet settled.
     *
     * @return list<self>
     */
    public static function openStatuses(): array
    {
        return [self::Issued, self::Sent, self::Reminded];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::openStatuses(), true);
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => in_array($next, [self::Issued, self::Sent], true),
            self::Issued => in_array($next, [self::Sent, self::Paid, self::Cancelled, self::Credited], true),
            self::Sent => in_array($next, [self::Reminded, self::Paid, self::Cancelled, self::Credited], true),
            self::Reminded => in_array($next, [self::Paid, self::Cancelled, self::Credited], true),
            self::Paid => $next === self::Credited,
            self::Cancelled => false,
            self::Credited => false,
        };
    }
}
