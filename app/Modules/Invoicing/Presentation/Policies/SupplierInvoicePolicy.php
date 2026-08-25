<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Policies;

use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Domain\Contracts\Actor;
use App\Modules\Shared\Policies\InteractsWithAccount;

class SupplierInvoicePolicy
{
    use InteractsWithAccount;

    public function viewAny(Actor $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(Actor $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->sameAccount($user, $supplierInvoice->user_id) && $user->can('invoices.view');
    }

    public function create(Actor $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function update(Actor $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->sameAccount($user, $supplierInvoice->user_id)
            && $user->can('invoices.manage')
            && $supplierInvoice->isEditable();
    }

    /**
     * Status transitions (received → booked/paid, …) must work on non-draft
     * supplier invoices, so this deliberately skips the isEditable() check.
     */
    public function updateStatus(Actor $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->sameAccount($user, $supplierInvoice->user_id)
            && $user->can('invoices.manage');
    }

    /**
     * Register verification runs on non-draft invoices too (that's where it
     * matters — right before paying), so no isEditable() check.
     */
    public function verifyAccount(Actor $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->sameAccount($user, $supplierInvoice->user_id)
            && $user->can('invoices.manage');
    }

    public function delete(Actor $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->sameAccount($user, $supplierInvoice->user_id)
            && $user->can('invoices.manage')
            && $supplierInvoice->isEditable();
    }
}
