<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application\Contracts;

use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Pricing\Domain\Models\Rate;

interface RecordOrderRateChangeActionInterface
{
    public function execute(Order $order, ?float $rate): Rate;
}
