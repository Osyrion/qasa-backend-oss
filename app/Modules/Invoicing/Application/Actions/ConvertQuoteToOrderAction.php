<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Orders\Application\Contracts\CreateOrderActionInterface;
use App\Modules\Orders\Application\DTOs\OrderData;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Enums\BillingType;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class ConvertQuoteToOrderAction
{
    public function __construct(
        private CreateOrderActionInterface $createOrderAction,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Quote $quote): Order
    {
        $this->assertConvertible($quote);

        return DB::transaction(function () use ($quote): Order {
            $quote->loadMissing('items');

            $order = $this->createOrderAction->execute(
                new OrderData(
                    name: $quote->quote_number,
                    billing_type: BillingType::Mixed,
                    client_id: $quote->client_id,
                    color: null,
                    readme: null,
                    rate: null,
                    currency: $quote->currency,
                    estimated_hours: null,
                    estimated_price: null,
                    deadline: null,
                ),
                $quote->user_id,
            );

            foreach ($quote->items as $item) {
                $orderItem = $order->items()->make([
                    'type' => 'service',
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'vat_rate' => $item->vat_rate,
                    'sort_order' => $item->sort_order,
                ]);
                $orderItem->recalculate();
                $orderItem->save();
            }

            $quote->forceFill(['converted_order_id' => $order->id])->save();

            return $order;
        });
    }

    /**
     * @throws DomainException
     */
    private function assertConvertible(Quote $quote): void
    {
        if ($quote->isConverted()) {
            throw DomainException::because(__('invoicing.quote_already_converted'));
        }

        if (! in_array($quote->status, ['sent', 'accepted'], true)) {
            throw DomainException::because(__('invoicing.quote_convert_invalid_status'));
        }
    }
}
