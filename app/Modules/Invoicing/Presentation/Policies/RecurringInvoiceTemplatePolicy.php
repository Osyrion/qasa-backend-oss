<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use App\Modules\Shared\Policies\InteractsWithAccount;

class RecurringInvoiceTemplatePolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(User $user, RecurringInvoiceTemplate $template): bool
    {
        return $this->sameAccount($user, $template->user_id) && $user->can('invoices.view');
    }

    public function create(User $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function update(User $user, RecurringInvoiceTemplate $template): bool
    {
        return $this->sameAccount($user, $template->user_id) && $user->can('invoices.manage');
    }

    public function delete(User $user, RecurringInvoiceTemplate $template): bool
    {
        return $this->sameAccount($user, $template->user_id) && $user->can('invoices.manage');
    }
}
