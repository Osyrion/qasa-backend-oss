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

        // Registration. Tighter than the surrounding group because each call
        // that succeeds creates an account and, on the SaaS edition, a trial
        // entitlement — the thing throwaway signups are after.
        RateLimiter::for('register', function (Request $request): Limit {
            return Limit::perMinute(5)->by('ip:'.$request->ip());
        });

        // Asking for an SMS code costs real money on every call, so this one
        // is about spend as much as security. The per-number hourly cap is
        // the half that survives an attacker rotating IPs — it bounds what
        // can be spent flooding any single handset.
        RateLimiter::for('phone-send', function (Request $request): array {
            $phone = Str::lower((string) $request->input('phone'));
            $user = $request->user()?->getAuthIdentifier();

            return [
                Limit::perMinute(3)->by('ip:'.$request->ip()),
                Limit::perHour(5)->by('phone:'.($phone !== '' ? $phone : $request->ip())),
                // Per account, because the per-number cap above resets with
                // every new number typed — one caller walking a list of
                // handsets would otherwise never hit a wall. This is the
                // limit that actually bounds what a single account can spend
                // in a day.
                Limit::perDay(10)->by('user:'.($user ?? $request->ip())),
            ];
        });

        // Keyed on the user, not the number: this request carries a code and
        // no phone at all, so a phone-keyed limit would collapse onto the IP
        // fallback and cap nothing per account.
        RateLimiter::for('phone-verify', function (Request $request): array {
            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('user:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
            ];
        });
    }
}
