<?php

declare(strict_types=1);

namespace App\Modules\Orders\Infrastructure\Providers;

use App\Modules\Orders\Application\Actions\CreateOrderAction;
use App\Modules\Orders\Application\Contracts\BillableWorkProviderInterface;
use App\Modules\Orders\Application\Contracts\CreateOrderActionInterface;
use App\Modules\Orders\Application\Contracts\OrderRateRecorderInterface;
use App\Modules\Orders\Application\Contracts\OrderRateResolverInterface;
use App\Modules\Orders\Application\Contracts\OrderRepositoryInterface;
use App\Modules\Orders\Application\Services\NoBillableWorkProvider;
use App\Modules\Orders\Application\Services\NoRateHistoryResolver;
use App\Modules\Orders\Application\Services\NullOrderRateRecorder;
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

        // OSS core defaults (no rate history); PricingServiceProvider rebinds
        // both — it registers after this provider, so its bindings win.
        $this->app->bind(
            OrderRateRecorderInterface::class,
            NullOrderRateRecorder::class,
        );

        $this->app->bind(
            OrderRateResolverInterface::class,
            NoRateHistoryResolver::class,
        );

        // OSS core default (no tracked work); TimeTrackingServiceProvider
        // rebinds it to the TimeEntry-backed implementation.
        $this->app->bind(
            BillableWorkProviderInterface::class,
            NoBillableWorkProvider::class,
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/orders.php'));

        Gate::policy(Order::class, OrderPolicy::class);
    }
}
