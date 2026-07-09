<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

enum InvoiceInboxStatus: string
{
    case Pending = 'pending';
    case Imported = 'imported';
    case Ignored = 'ignored';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('invoicing.inbox.status.pending'),
            self::Imported => __('invoicing.inbox.status.imported'),
            self::Ignored => __('invoicing.inbox.status.ignored'),
            self::Failed => __('invoicing.inbox.status.failed'),
        };
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Imported and ignored are terminal — the item has already been acted
     * on, so it can no longer be converted or ignored again.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Imported, self::Ignored], true);
    }

    public function canConvert(): bool
    {
        return ! $this->isTerminal();
    }
}
