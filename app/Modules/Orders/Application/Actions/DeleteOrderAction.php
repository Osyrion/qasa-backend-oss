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
            throw DomainException::because(__('orders.cannot_delete_invoiced'));
        }

        DB::transaction(function () use ($order): void {
            // Modules that hold a reference to the order (e.g. Calendar's
            // events.order_id) unlink on this event — a soft delete never
            // fires their nullOnDelete FK on its own.
            event(new OrderDeleted($order));
            $this->repository->delete($order);
        });
    }
}
