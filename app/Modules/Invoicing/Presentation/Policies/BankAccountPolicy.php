<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Shared\Policies\InteractsWithAccount;

class BankAccountPolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(User $user, BankAccount $bankAccount): bool
    {
        return $this->sameAccount($user, $bankAccount->user_id) && $user->can('invoices.view');
    }

    public function create(User $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function update(User $user, BankAccount $bankAccount): bool
    {
        return $this->sameAccount($user, $bankAccount->user_id) && $user->can('invoices.manage');
    }

    public function delete(User $user, BankAccount $bankAccount): bool
    {
        return $this->sameAccount($user, $bankAccount->user_id) && $user->can('invoices.manage');
    }
}
