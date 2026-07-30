<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        // Baseline limiter for authenticated API routes — endpoints with
        // stricter needs (e-mail sending, uploads, public pages) stack their
        // own named limiters on top.
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)->by(
                $request->user()?->getAuthIdentifier() ?? $request->ip()
            );
        });

        // Credential-checking endpoints (login, Google callback, 2FA verify).
        // The per-IP limit stops one host hammering; the per-email limit caps
        // attempts against a *single account* across a botnet of IPs, which a
        // pure per-IP throttle can't see. Keyed on the email so it only ever
        // bites the account under attack.
        RateLimiter::for('auth-login', function (Request $request): array {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(10)->by('ip:'.$request->ip()),
                Limit::perMinute(20)->by('email:'.($email !== '' ? $email : $request->ip())),
            ];
        });
    }
}
