<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Policies;

use App\Modules\Shared\Domain\Contracts\Actor;
use App\Modules\Shared\Policies\InteractsWithAccount;
use App\Modules\Taxation\Domain\Models\ContributionPayment;

class ContributionPaymentPolicy
{
    use InteractsWithAccount;

    public function viewAny(Actor $user): bool
    {
        return $user->can('taxation.view');
    }

    public function view(Actor $user, ContributionPayment $payment): bool
    {
        return $this->sameAccount($user, $payment->user_id) && $user->can('taxation.view');
    }

    public function create(Actor $user): bool
    {
        return $user->can('taxation.manage');
    }

    public function update(Actor $user, ContributionPayment $payment): bool
    {
        return $this->sameAccount($user, $payment->user_id) && $user->can('taxation.manage');
    }

    public function delete(Actor $user, ContributionPayment $payment): bool
    {
        return $this->sameAccount($user, $payment->user_id) && $user->can('taxation.manage');
    }
}
