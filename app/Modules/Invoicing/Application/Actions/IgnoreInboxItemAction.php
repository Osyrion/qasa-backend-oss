<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Domain\Enums\InvoiceInboxStatus;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use App\Modules\Shared\Exceptions\DomainException;

readonly class IgnoreInboxItemAction
{
    /**
     * @throws DomainException
     */
    public function execute(InvoiceInboxItem $item): InvoiceInboxItem
    {
        if (! $item->statusEnum()->canConvert()) {
            throw DomainException::because(__('invoicing.inbox.already_processed'));
        }

        $item->status = InvoiceInboxStatus::Ignored->value;
        $item->save();

        return $item;
    }
}
