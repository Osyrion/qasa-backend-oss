<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Policies;

use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Domain\Contracts\Actor;
use App\Modules\Shared\Policies\InteractsWithAccount;

class OrderPolicy
{
    use InteractsWithAccount;

    public function viewAny(Actor $user): bool
    {
        return $user->can('orders.view');
    }

    public function view(Actor $user, Order $order): bool
    {
        return $this->sameAccount($user, $order->user_id) && $user->can('orders.view');
    }

    public function create(Actor $user): bool
    {
        return $user->can('orders.manage');
    }

    public function update(Actor $user, Order $order): bool
    {
        return $this->sameAccount($user, $order->user_id)
            && $user->can('orders.manage')
            && ($order->status_enum?->isEditable() ?? false);
    }

    public function delete(Actor $user, Order $order): bool
    {
        return $this->sameAccount($user, $order->user_id) && $user->can('orders.manage');
    }
}
