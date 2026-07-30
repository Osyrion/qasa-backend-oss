<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Events;

use App\Modules\Orders\Domain\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderUpdated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
    ) {}
}
