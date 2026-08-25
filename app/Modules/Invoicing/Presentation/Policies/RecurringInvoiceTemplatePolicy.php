<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Policies;

use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use App\Modules\Shared\Domain\Contracts\Actor;
use App\Modules\Shared\Policies\InteractsWithAccount;

class RecurringInvoiceTemplatePolicy
{
    use InteractsWithAccount;

    public function viewAny(Actor $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(Actor $user, RecurringInvoiceTemplate $template): bool
    {
        return $this->sameAccount($user, $template->user_id) && $user->can('invoices.view');
    }

    public function create(Actor $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function update(Actor $user, RecurringInvoiceTemplate $template): bool
    {
        return $this->sameAccount($user, $template->user_id) && $user->can('invoices.manage');
    }

    public function delete(Actor $user, RecurringInvoiceTemplate $template): bool
    {
        return $this->sameAccount($user, $template->user_id) && $user->can('invoices.manage');
    }
}
