<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\VatRate;
use App\Modules\Shared\Policies\InteractsWithAccount;

class VatRatePolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(User $user, VatRate $vatRate): bool
    {
        return $this->sameAccount($user, $vatRate->user_id) && $user->can('invoices.view');
    }

    public function create(User $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function update(User $user, VatRate $vatRate): bool
    {
        return $this->sameAccount($user, $vatRate->user_id) && $user->can('invoices.manage');
    }

    public function delete(User $user, VatRate $vatRate): bool
    {
        return $this->sameAccount($user, $vatRate->user_id) && $user->can('invoices.manage');
    }
}
