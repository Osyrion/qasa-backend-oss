<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Application\Contracts\ClientUsageGuardInterface;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Orders\Application\Contracts\CreateOrderActionInterface;
use App\Modules\Orders\Application\Contracts\OrderRateRecorderInterface;
use App\Modules\Orders\Application\Contracts\OrderRepositoryInterface;
use App\Modules\Orders\Application\DTOs\OrderData;
use App\Modules\Orders\Domain\Enums\OrderStatus;
use App\Modules\Orders\Domain\Events\OrderCreated;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class CreateOrderAction implements CreateOrderActionInterface
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
    public function execute(OrderData $data, User $owner): Order
    {
        $this->validate($data);
        $this->validateLimit($owner);
        $this->validateCurrency($data, $owner->accountOwner());

        if ($data->client_id !== null) {
            $client = Client::query()->find($data->client_id);

            if ($client !== null) {
                if ($client->isArchived()) {
                    throw DomainException::because(__('clients.archived'));
                }

                $this->usageGuard->ensureUsable($client);
            }
        }

        return DB::transaction(function () use ($data, $owner): Order {
            $order = $this->repository->create([
                'user_id' => $owner->id,
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
                $this->rateRecorder->record($order, $data->rate);
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
                __('orders.personal_order_cannot_have_rate')
            );
        }

        // Non-mixed billing type should have a rate
        if ($data->client_id !== null
            && $data->billing_type->hasDefaultRate()
            && $data->rate === null
        ) {
            throw DomainException::because(
                __('orders.billable_type_requires_rate', ['type' => $data->billing_type->label()])
            );
        }
    }

    /**
     * Archived orders don't count — archiving is the natural "clean-up"
     * valve, so it shouldn't frustrate long-time users with a full history.
     * withinLimit() checks whether there is room for one more (strictly
     * less than the limit) — pass the count of orders that already exist,
     * not the count after this one is added.
     *
     * @throws DomainException
     */
    private function validateLimit(User $owner): void
    {
        $count = Order::forUser($owner->accountOwnerId())
            ->where('status', '!=', OrderStatus::Archived->value)
            ->count();

        if (! $owner->withinLimit('max_orders', $count)) {
            throw DomainException::because(__('orders.limit_reached'));
        }
    }

    /**
     * @throws DomainException
     */
    private function validateCurrency(OrderData $data, User $owner): void
    {
        if ($data->currency !== null
            && $data->currency !== $owner->default_currency
            && ! $owner->hasFeature('multi_currency')
        ) {
            throw DomainException::because(__('subscriptions.multi_currency_required'));
        }
    }
}
