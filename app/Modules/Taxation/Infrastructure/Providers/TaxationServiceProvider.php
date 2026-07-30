<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Providers;

use App\Modules\Taxation\Application\Contracts\TaxSystemResolverInterface;
use App\Modules\Taxation\Application\Services\TaxSystemResolver;
use App\Modules\Taxation\Domain\Models\ContributionPayment;
use App\Modules\Taxation\Infrastructure\Cz\CzTaxSystem;
use App\Modules\Taxation\Infrastructure\Sk\SkTaxSystem;
use App\Modules\Taxation\Presentation\Policies\ContributionPaymentPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class TaxationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TaxSystemResolverInterface::class, TaxSystemResolver::class);

        // CzTaxSystem/SkTaxSystem never name an accounting-export builder
        // directly — they pull whatever is tagged under
        // 'taxation.export_builders.cz'/'.sk'. Core tags nothing itself
        // (accountingExports() is an empty list in OSS); the premium
        // Accounting module's provider tags Pohoda/ISDOC/Omega there.
        $this->app->when(CzTaxSystem::class)
            ->needs('$exportBuilders')
            ->giveTagged('taxation.export_builders.cz');

        $this->app->when(SkTaxSystem::class)
            ->needs('$exportBuilders')
            ->giveTagged('taxation.export_builders.sk');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/taxation.php');
        $this->loadViewsFrom(__DIR__.'/../Views', 'taxation');

        Gate::policy(ContributionPayment::class, ContributionPaymentPolicy::class);

        // Authenticated (step 2 runs post-login), but still throttled — a
        // logged-in scraper against ARES/RPO is still a scraper.
        RateLimiter::for('registry-lookup', function (Request $request): Limit {
            return Limit::perMinute(10)->by(
                (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
            );
        });

        RateLimiter::for('tax-return-preview', function (Request $request): Limit {
            return Limit::perMinute(30)->by(
                (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
            );
        });
    }
}
