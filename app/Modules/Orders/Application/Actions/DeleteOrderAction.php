<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Orders\Application\Contracts\OrderRepositoryInterface;
use App\Modules\Orders\Domain\Events\OrderDeleted;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class DeleteOrderAction
{
    public function __construct(
        private OrderRepositoryInterface $repository,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Order $order): void
    {
        $hasInvoicedItems = DB::table('invoice_items')
            ->whereIn('order_item_id', $order->items()->select('id'))
            ->exists();

        if ($hasInvoicedItems) {
            throw DomainException::because(
                'Zákazku nie je možné zmazať, pretože obsahuje vyfakturované položky.'
            );
        }

        DB::transaction(function () use ($order): void {
            event(new OrderDeleted($order));

            $this->repository->delete($order);
        });
    }
}
