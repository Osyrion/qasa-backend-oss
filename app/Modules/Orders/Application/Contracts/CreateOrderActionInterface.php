<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Contracts;

use App\Modules\Orders\Application\DTOs\OrderData;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Exceptions\DomainException;
use Throwable;

interface CreateOrderActionInterface
{
    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(OrderData $data, string $userId): Order;
}
