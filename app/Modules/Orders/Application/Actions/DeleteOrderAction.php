<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Calendar\Domain\Models\Event;
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
        $hasInvoicedItems = $order->items()
            ->whereHas('timeEntry', fn ($q) => $q->where('is_invoiced', true))
            ->exists();

        if ($hasInvoicedItems) {
            throw DomainException::because(
                'Zákazku nie je možné zmazať, pretože obsahuje vyfakturované položky.'
            );
        }

        DB::transaction(function () use ($order): void {
            event(new OrderDeleted($order));

            // Order deletion is a soft delete (the row survives), so the
            // events.order_id nullOnDelete FK never fires on its own —
            // unlink explicitly so calendar events aren't left pointing at
            // a now-invisible order.
            Event::withoutGlobalScope('user')
                ->where('order_id', $order->id)
                ->update(['order_id' => null]);

            $this->repository->delete($order);
        });
    }
}
