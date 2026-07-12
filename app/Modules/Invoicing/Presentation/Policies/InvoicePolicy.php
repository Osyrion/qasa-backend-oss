<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Policies\InteractsWithAccount;

class InvoicePolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->sameAccount($user, $invoice->user_id) && $user->can('invoices.view');
    }

    public function create(User $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $this->sameAccount($user, $invoice->user_id)
            && $user->can('invoices.manage')
            && $invoice->isEditable();
    }

    /**
     * Status transitions (sent → paid, …) must work on non-draft invoices,
     * so this deliberately skips the isEditable() check in update().
     */
    public function updateStatus(User $user, Invoice $invoice): bool
    {
        return $this->sameAccount($user, $invoice->user_id)
            && $user->can('invoices.manage');
    }

    /**
     * Emailing must work on drafts (issued on the fly) and issued invoices
     * alike, so this mirrors updateStatus() rather than update().
     */
    public function email(User $user, Invoice $invoice): bool
    {
        return $this->sameAccount($user, $invoice->user_id)
            && $user->can('invoices.manage');
    }

    public function remind(User $user, Invoice $invoice): bool
    {
        return $this->sameAccount($user, $invoice->user_id)
            && $user->can('invoices.manage');
    }

    /**
     * Public link creation only makes sense on issued (non-draft) invoices,
     * so this mirrors email()/remind() rather than update().
     */
    public function publicLink(User $user, Invoice $invoice): bool
    {
        return $this->sameAccount($user, $invoice->user_id)
            && $user->can('invoices.manage');
    }

    public function recordPayment(User $user, Invoice $invoice): bool
    {
        return $this->sameAccount($user, $invoice->user_id)
            && $user->can('invoices.manage');
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->sameAccount($user, $invoice->user_id)
            && $user->can('invoices.manage')
            && $invoice->isDraft();
    }
}
