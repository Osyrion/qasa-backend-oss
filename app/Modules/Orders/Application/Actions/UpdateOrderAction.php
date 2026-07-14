<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Orders\Application\Contracts\OrderRepositoryInterface;
use App\Modules\Orders\Application\DTOs\OrderData;
use App\Modules\Orders\Domain\Enums\OrderStatus;
use App\Modules\Orders\Domain\Events\OrderUpdated;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Pricing\Application\Contracts\RecordOrderRateChangeActionInterface;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class UpdateOrderAction
{
    public function __construct(
        private OrderRepositoryInterface $repository,
        private RecordOrderRateChangeActionInterface $recordRateChange,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Order $order, OrderData $data): Order
    {
        /** @var OrderStatus $status */
        $status = $order->status_enum;

        if (! $status->isEditable()) {
            throw DomainException::because(
                "Zákazku so statusom {$status->label()} nie je možné upraviť."
            );
        }

        return DB::transaction(function () use ($order, $data): Order {
            $previousRate = $order->rate !== null ? (float) $order->rate : null;

            $updated = $this->repository->update($order, [
                'client_id' => $data->client_id,
                'name' => $data->name,
                'color' => $data->color,
                'readme' => $data->readme,
                'status' => $data->status,
                'billing_type' => $data->billing_type->value,
                'rate' => $data->rate,
                'currency' => $data->currency?->value,
                'estimated_hours' => $data->estimated_hours,
                'estimated_price' => $data->estimated_price,
                'deadline' => $data->deadline,
            ]);

            // Append-only rate history: a changed (or removed) rate gets a new
            // dated row, so work logged before today keeps its old pricing.
            if ($previousRate !== $data->rate && ($previousRate !== null || $data->rate !== null)) {
                $this->recordRateChange->execute($updated, $data->rate);
            }

            event(new OrderUpdated($updated));

            return $updated;
        });
    }
}
