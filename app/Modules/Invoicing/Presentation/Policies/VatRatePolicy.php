<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Policies;

use App\Modules\Invoicing\Domain\Models\VatRate;
use App\Modules\Shared\Domain\Contracts\Actor;
use App\Modules\Shared\Policies\InteractsWithAccount;

class VatRatePolicy
{
    use InteractsWithAccount;

    public function viewAny(Actor $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(Actor $user, VatRate $vatRate): bool
    {
        return $this->sameAccount($user, $vatRate->user_id) && $user->can('invoices.view');
    }

    public function create(Actor $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function update(Actor $user, VatRate $vatRate): bool
    {
        return $this->sameAccount($user, $vatRate->user_id) && $user->can('invoices.manage');
    }

    public function delete(Actor $user, VatRate $vatRate): bool
    {
        return $this->sameAccount($user, $vatRate->user_id) && $user->can('invoices.manage');
    }
}
