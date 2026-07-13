<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure\Providers;

use App\Modules\Pricing\Application\Actions\RecordOrderRateChangeAction;
use App\Modules\Pricing\Application\Contracts\RateResolverInterface;
use App\Modules\Pricing\Application\Contracts\RecordOrderRateChangeActionInterface;
use App\Modules\Pricing\Application\Services\RateResolver;
use App\Modules\Pricing\Domain\Models\PriceList;
use App\Modules\Pricing\Domain\Models\Rate;
use App\Modules\Pricing\Presentation\Policies\PriceListPolicy;
use App\Modules\Pricing\Presentation\Policies\RatePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            RateResolverInterface::class,
            RateResolver::class,
        );

        $this->app->bind(
            RecordOrderRateChangeActionInterface::class,
            RecordOrderRateChangeAction::class,
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/pricing.php');

        Gate::policy(Rate::class, RatePolicy::class);
        Gate::policy(PriceList::class, PriceListPolicy::class);
    }
}
