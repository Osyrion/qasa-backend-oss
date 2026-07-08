<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Pricing\Domain\Models\PriceList;
use App\Modules\Shared\Policies\InteractsWithAccount;

class PriceListPolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('pricing.view');
    }

    public function view(User $user, PriceList $priceList): bool
    {
        return $this->sameAccount($user, $priceList->user_id) && $user->can('pricing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('pricing.manage');
    }

    public function update(User $user, PriceList $priceList): bool
    {
        return $this->sameAccount($user, $priceList->user_id) && $user->can('pricing.manage');
    }

    public function delete(User $user, PriceList $priceList): bool
    {
        return $this->sameAccount($user, $priceList->user_id) && $user->can('pricing.manage');
    }
}
