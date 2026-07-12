<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Orders\Application\Contracts\CreateOrderActionInterface;
use App\Modules\Orders\Application\Contracts\OrderRepositoryInterface;
use App\Modules\Orders\Application\DTOs\OrderData;
use App\Modules\Orders\Domain\Events\OrderCreated;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Pricing\Application\Contracts\RecordOrderRateChangeActionInterface;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class CreateOrderAction implements CreateOrderActionInterface
{
    public function __construct(
        private OrderRepositoryInterface $repository,
        private RecordOrderRateChangeActionInterface $recordRateChange,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(OrderData $data, string $userId): Order
    {
        $this->validate($data);

        return DB::transaction(function () use ($data, $userId): Order {
            $order = $this->repository->create([
                'user_id' => $userId,
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

            if ($data->rate !== null) {
                $this->recordRateChange->execute($order, $data->rate);
            }

            event(new OrderCreated($order));

            return $order;
        });
    }

    /**
     * @throws DomainException
     */
    private function validate(OrderData $data): void
    {
        // Personal order cannot have a rate — no client to bill
        if ($data->client_id === null && $data->rate !== null) {
            throw DomainException::because(
                'Osobná zákazka (bez klienta) nemôže mať nastavenú sadzbu.'
            );
        }

        // Non-mixed billing type should have a rate
        if ($data->client_id !== null
            && $data->billing_type->hasDefaultRate()
            && $data->rate === null
        ) {
            throw DomainException::because(
                "Fakturovateľná zákazka s typom {$data->billing_type->label()} musí mať nastavenú sadzbu."
            );
        }
    }
}
