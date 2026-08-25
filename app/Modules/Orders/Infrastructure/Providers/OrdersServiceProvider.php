<?php

declare(strict_types=1);

namespace App\Modules\Orders\Infrastructure\Providers;

use App\Modules\Orders\Application\Actions\CreateOrderAction;
use App\Modules\Orders\Application\Contracts\BillableWorkProviderInterface;
use App\Modules\Orders\Application\Contracts\CreateOrderActionInterface;
use App\Modules\Orders\Application\Contracts\OrderAuthorization;
use App\Modules\Orders\Application\Contracts\OrderLookup;
use App\Modules\Orders\Application\Contracts\OrderRateCache;
use App\Modules\Orders\Application\Contracts\OrderRateRecorderInterface;
use App\Modules\Orders\Application\Contracts\OrderRateResolverInterface;
use App\Modules\Orders\Application\Contracts\OrderRepositoryInterface;
use App\Modules\Orders\Application\Contracts\OrderRepresentation;
use App\Modules\Orders\Application\Services\EloquentOrderLookup;
use App\Modules\Orders\Application\Services\GateOrderAuthorization;
use App\Modules\Orders\Application\Services\NoBillableWorkProvider;
use App\Modules\Orders\Application\Services\NoRateHistoryResolver;
use App\Modules\Orders\Application\Services\NullOrderRateRecorder;
use App\Modules\Orders\Application\Services\OrderLinkableRecords;
use App\Modules\Orders\Application\Services\OrdersAccountData;
use App\Modules\Orders\Application\Services\OrdersDashboardStats;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Infrastructure\Repositories\EloquentOrderRepository;
use App\Modules\Orders\Presentation\Policies\OrderPolicy;
use App\Modules\Orders\Presentation\Support\OrderResourceRepresentation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class OrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Orders' section of the GDPR account export — Auth assembles the
        // payload but must not know what an order row looks like.
        $this->app->tag([OrdersAccountData::class], ['account.export']);
        $this->app->tag([OrdersDashboardStats::class], ['dashboard.stats']);
        $this->app->tag([OrderLinkableRecords::class], ['linkable.records']);

        $this->app->bind(
            OrderRepositoryInterface::class,
            EloquentOrderRepository::class,
        );

        // One class answers both: reading an order and writing back the rate
        // Pricing resolved for it are the same table, and splitting them into
        // two services would only mean two copies of the same query.
        $this->app->singleton(EloquentOrderLookup::class);
        $this->app->bind(OrderLookup::class, EloquentOrderLookup::class);
        $this->app->bind(OrderRateCache::class, EloquentOrderLookup::class);
        $this->app->bind(OrderAuthorization::class, GateOrderAuthorization::class);
        $this->app->bind(OrderRepresentation::class, OrderResourceRepresentation::class);

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
