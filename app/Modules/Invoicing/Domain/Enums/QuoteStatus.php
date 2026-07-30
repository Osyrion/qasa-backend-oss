<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Koncept',
            self::Sent => 'Odoslaná',
            self::Accepted => 'Prijatá',
            self::Rejected => 'Odmietnutá',
            self::Expired => 'Expirovaná',
        };
    }

    /**
     * Items and header can only change while the quote is still a draft —
     * once sent, the client is deciding on a fixed offer.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Accepted, self::Rejected, self::Expired], true);
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => $next === self::Sent,
            self::Sent => in_array($next, [self::Accepted, self::Rejected, self::Expired], true),
            self::Accepted, self::Rejected, self::Expired => false,
        };
    }
}
