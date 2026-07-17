<?php

declare(strict_types=1);

namespace App\Providers;

use App\Mcp\Servers\QasaServer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mcp::local('qasa', QasaServer::class);

        // The whole API (spec, generated frontend types, tests) assumes bare
        // single-resource/list payloads; only paginated endpoints keep their
        // {data, meta} shape (Laravel force-wraps those via the links/meta merge
        // regardless of this setting).
        JsonResource::withoutWrapping();

        // Baseline limiter for authenticated API routes — endpoints with
        // stricter needs (e-mail sending, uploads, public pages) stack their
        // own named limiters on top.
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)->by(
                $request->user()?->getAuthIdentifier() ?? $request->ip()
            );
        });
    }
}
