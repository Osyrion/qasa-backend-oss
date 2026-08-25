<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Models\QuoteItem;
use App\Modules\Orders\Application\Contracts\CreateOrderActionInterface;
use App\Modules\Orders\Application\DTOs\OrderData;
use App\Modules\Orders\Domain\ValueObjects\OrderItemDraft;
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
     * @return string the new order's id
     *
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Quote $quote): string
    {
        $this->assertConvertible($quote);

        return DB::transaction(function () use ($quote): string {
            $quote->loadMissing(['items', 'user']);
            $user = $quote->user;
            assert($user !== null);

            $orderId = $this->createOrderAction->create(
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
                $user,
                array_values($quote->items->map(static fn (QuoteItem $item): OrderItemDraft => new OrderItemDraft(
                    description: $item->description,
                    quantity: (float) $item->quantity,
                    unit: $item->unit,
                    unitPrice: (float) $item->unit_price,
                    vatRate: (float) $item->vat_rate,
                    sortOrder: $item->sort_order,
                ))->all()),
            );

            $quote->forceFill(['converted_order_id' => $orderId])->save();

            return $orderId;
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
