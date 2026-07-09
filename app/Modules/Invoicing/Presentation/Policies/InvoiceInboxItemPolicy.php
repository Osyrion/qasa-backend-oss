<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use App\Modules\Shared\Policies\InteractsWithAccount;

class InvoiceInboxItemPolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(User $user, InvoiceInboxItem $inboxItem): bool
    {
        return $this->sameAccount($user, $inboxItem->user_id) && $user->can('invoices.view');
    }

    public function convert(User $user, InvoiceInboxItem $inboxItem): bool
    {
        return $this->sameAccount($user, $inboxItem->user_id) && $user->can('invoices.manage');
    }

    public function ignore(User $user, InvoiceInboxItem $inboxItem): bool
    {
        return $this->sameAccount($user, $inboxItem->user_id) && $user->can('invoices.manage');
    }

    public function delete(User $user, InvoiceInboxItem $inboxItem): bool
    {
        return $this->sameAccount($user, $inboxItem->user_id) && $user->can('invoices.manage');
    }
}
