<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientUsageGuardInterface;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Orders\Application\Contracts\OrderRateRecorderInterface;
use App\Modules\Orders\Application\Contracts\OrderRepositoryInterface;
use App\Modules\Orders\Application\DTOs\OrderData;
use App\Modules\Orders\Domain\Events\OrderUpdated;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class UpdateOrderAction
{
    public function __construct(
        private OrderRepositoryInterface $repository,
        private OrderRateRecorderInterface $rateRecorder,
        private ClientUsageGuardInterface $usageGuard,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Order $order, OrderData $data): Order
    {
        if (! ($order->status_enum?->isEditable() ?? false)) {
            throw DomainException::because(
                __('orders.status_not_editable', ['status' => $order->status])
            );
        }

        if ($order->client !== null) {
            $this->usageGuard->ensureUsable($order->client);
        }

        // Retargeting the order to a locked client is blocked as well.
        if ($data->client_id !== null && $data->client_id !== $order->client_id) {
            $client = Client::query()->find($data->client_id);

            if ($client !== null) {
                $this->usageGuard->ensureUsable($client);
            }
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
                $this->rateRecorder->record($updated, $data->rate);
            }

            event(new OrderUpdated($updated));

            return $updated;
        });
    }
}
