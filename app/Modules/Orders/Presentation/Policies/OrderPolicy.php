<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Policies\InteractsWithAccount;

class OrderPolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('orders.view');
    }

    public function view(User $user, Order $order): bool
    {
        return $this->sameAccount($user, $order->user_id) && $user->can('orders.view');
    }

    public function create(User $user): bool
    {
        return $user->can('orders.manage');
    }

    public function update(User $user, Order $order): bool
    {
        return $this->sameAccount($user, $order->user_id)
            && $user->can('orders.manage')
            && $order->status_enum->isEditable();
    }

    public function delete(User $user, Order $order): bool
    {
        return $this->sameAccount($user, $order->user_id) && $user->can('orders.manage');
    }
}
