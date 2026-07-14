<?php

declare(strict_types=1);

namespace App\Modules\Orders\Infrastructure\Providers;

use App\Modules\Orders\Application\Actions\CreateOrderAction;
use App\Modules\Orders\Application\Contracts\CreateOrderActionInterface;
use App\Modules\Orders\Application\Contracts\OrderRepositoryInterface;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Infrastructure\Repositories\EloquentOrderRepository;
use App\Modules\Orders\Presentation\Policies\OrderPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class OrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            OrderRepositoryInterface::class,
            EloquentOrderRepository::class,
        );

        $this->app->bind(
            CreateOrderActionInterface::class,
            CreateOrderAction::class,
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/orders.php');

        Gate::policy(Order::class, OrderPolicy::class);
    }
}
