<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Pricing\Domain\Models\Rate;
use App\Modules\Shared\Policies\InteractsWithAccount;

class RatePolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('pricing.view');
    }

    public function view(User $user, Rate $rate): bool
    {
        return $this->sameAccount($user, $rate->user_id) && $user->can('pricing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('pricing.manage');
    }

    public function delete(User $user, Rate $rate): bool
    {
        return $this->sameAccount($user, $rate->user_id) && $user->can('pricing.manage');
    }
}
