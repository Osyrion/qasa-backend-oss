<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Policies;

use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use App\Modules\Shared\Domain\Contracts\Actor;
use App\Modules\Shared\Policies\InteractsWithAccount;

class InvoiceInboxItemPolicy
{
    use InteractsWithAccount;

    public function viewAny(Actor $user): bool
    {
        return $user->can('invoices.view');
    }

    public function create(Actor $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function view(Actor $user, InvoiceInboxItem $inboxItem): bool
    {
        return $this->sameAccount($user, $inboxItem->user_id) && $user->can('invoices.view');
    }

    public function convert(Actor $user, InvoiceInboxItem $inboxItem): bool
    {
        return $this->sameAccount($user, $inboxItem->user_id) && $user->can('invoices.manage');
    }

    public function ignore(Actor $user, InvoiceInboxItem $inboxItem): bool
    {
        return $this->sameAccount($user, $inboxItem->user_id) && $user->can('invoices.manage');
    }

    public function delete(Actor $user, InvoiceInboxItem $inboxItem): bool
    {
        return $this->sameAccount($user, $inboxItem->user_id) && $user->can('invoices.manage');
    }
}
