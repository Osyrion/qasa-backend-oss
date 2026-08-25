<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Policies;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Domain\Contracts\Actor;
use App\Modules\Shared\Policies\InteractsWithAccount;

class InvoicePolicy
{
    use InteractsWithAccount;

    public function viewAny(Actor $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(Actor $user, Invoice $invoice): bool
    {
        return $this->sameAccount($user, $invoice->user_id) && $user->can('invoices.view');
    }

    public function create(Actor $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function update(Actor $user, Invoice $invoice): bool
    {
        return $this->sameAccount($user, $invoice->user_id)
            && $user->can('invoices.manage')
            && $invoice->isEditable();
    }

    /**
     * Status transitions (sent → paid, …) must work on non-draft invoices,
     * so this deliberately skips the isEditable() check in update().
     */
    public function updateStatus(Actor $user, Invoice $invoice): bool
    {
        return $this->sameAccount($user, $invoice->user_id)
            && $user->can('invoices.manage');
    }

    /**
     * Emailing must work on drafts (issued on the fly) and issued invoices
     * alike, so this mirrors updateStatus() rather than update().
     */
    public function email(Actor $user, Invoice $invoice): bool
    {
        return $this->sameAccount($user, $invoice->user_id)
            && $user->can('invoices.manage');
    }

    public function remind(Actor $user, Invoice $invoice): bool
    {
        return $this->sameAccount($user, $invoice->user_id)
            && $user->can('invoices.manage');
    }

    /**
     * Public link creation only makes sense on issued (non-draft) invoices,
     * so this mirrors email()/remind() rather than update().
     */
    public function publicLink(Actor $user, Invoice $invoice): bool
    {
        return $this->sameAccount($user, $invoice->user_id)
            && $user->can('invoices.manage');
    }

    public function recordPayment(Actor $user, Invoice $invoice): bool
    {
        return $this->sameAccount($user, $invoice->user_id)
            && $user->can('invoices.manage');
    }

    /**
     * Settling a proforma creates a new, separate invoice, so this mirrors
     * updateStatus()/recordPayment() rather than update().
     */
    public function settle(Actor $user, Invoice $invoice): bool
    {
        return $this->sameAccount($user, $invoice->user_id)
            && $user->can('invoices.manage');
    }

    public function delete(Actor $user, Invoice $invoice): bool
    {
        return $this->sameAccount($user, $invoice->user_id)
            && $user->can('invoices.manage')
            && $invoice->isDraft();
    }
}
