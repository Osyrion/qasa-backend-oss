<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Policies\InteractsWithAccount;
use App\Modules\Taxation\Domain\Models\ContributionPayment;

class ContributionPaymentPolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('taxation.view');
    }

    public function view(User $user, ContributionPayment $payment): bool
    {
        return $this->sameAccount($user, $payment->user_id) && $user->can('taxation.view');
    }

    public function create(User $user): bool
    {
        return $user->can('taxation.manage');
    }

    public function update(User $user, ContributionPayment $payment): bool
    {
        return $this->sameAccount($user, $payment->user_id) && $user->can('taxation.manage');
    }

    public function delete(User $user, ContributionPayment $payment): bool
    {
        return $this->sameAccount($user, $payment->user_id) && $user->can('taxation.manage');
    }
}
