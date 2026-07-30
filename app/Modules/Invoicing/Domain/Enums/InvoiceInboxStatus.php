<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

enum InvoiceInboxStatus: string
{
    case Processing = 'processing';
    case Pending = 'pending';
    case Imported = 'imported';
    case Ignored = 'ignored';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Processing => __('invoicing.inbox.status.processing'),
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

    /**
     * Only a fully processed item (pending suggestions, or a failed OCR
     * pass) can be converted or ignored — one still being processed has no
     * suggestions yet, and letting it through would race the queued job.
     */
    public function canConvert(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }
}
