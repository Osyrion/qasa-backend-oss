<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Policies\InteractsWithAccount;

class SupplierInvoicePolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(User $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->sameAccount($user, $supplierInvoice->user_id) && $user->can('invoices.view');
    }

    public function create(User $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function update(User $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->sameAccount($user, $supplierInvoice->user_id)
            && $user->can('invoices.manage')
            && $supplierInvoice->isEditable();
    }

    /**
     * Status transitions (received → booked/paid, …) must work on non-draft
     * supplier invoices, so this deliberately skips the isEditable() check.
     */
    public function updateStatus(User $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->sameAccount($user, $supplierInvoice->user_id)
            && $user->can('invoices.manage');
    }

    /**
     * Register verification runs on non-draft invoices too (that's where it
     * matters — right before paying), so no isEditable() check.
     */
    public function verifyAccount(User $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->sameAccount($user, $supplierInvoice->user_id)
            && $user->can('invoices.manage');
    }

    public function delete(User $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->sameAccount($user, $supplierInvoice->user_id)
            && $user->can('invoices.manage')
            && $supplierInvoice->isEditable();
    }
}
