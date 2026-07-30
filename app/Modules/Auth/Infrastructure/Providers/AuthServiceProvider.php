<?php

declare(strict_types=1);

namespace App\Modules\Auth\Infrastructure\Providers;

use App\Modules\Auth\Application\Services\AccountExportService;
use App\Modules\Auth\Application\Services\DashboardService;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Auth\Infrastructure\Sanctum\TenantAwarePersonalAccessToken;
use App\Modules\Auth\Presentation\Console\CreateUserCommand;
use App\Modules\Shared\Authorization\AbilityCatalog;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Both payloads are assembled from sections other modules own — the
        // tag is empty in the OSS edition, which is exactly how those keys
        // disappear from the response there.
        $this->app->bind(
            DashboardService::class,
            fn ($app): DashboardService => new DashboardService($app->tagged('dashboard.stats')),
        );

        $this->app->bind(
            AccountExportService::class,
            fn ($app): AccountExportService => new AccountExportService($app->tagged('account.export')),
        );

        // Token scopes: a scoped personal access token (created via
        // POST /auth/tokens) can only use the abilities it was granted.
        // Registered in register() — not boot() — so it resolves the Gate
        // (and lands first in its beforeCallbacks list) before Spatie's
        // laravel-permission package gets a chance to. Spatie registers its
        // own Gate::before lazily via callAfterResolving(Gate::class, ...)
        // from its packageBooted(), which runs during the boot() phase; by
        // then Gate is already resolved (a singleton) so Spatie's callback
        // is appended after ours. Laravel evaluates beforeCallbacks in
        // registration order and stops at the first non-null result, so
        // this ordering is what lets a deny here win over the SaaS Team
        // module's role-based permission grant — otherwise an Owner's full
        // role permissions would grant every ability before the token's
        // actual scope is ever consulted, making scoped tokens a no-op.
        // Login/2FA tokens keep the default '*' ability and are unaffected.
        Gate::before(function (User $user, string $ability): ?bool {
            // Only scope the capability abilities themselves (the
            // clients.view / invoices.manage / ... catalogue, plus the
            // SaaS-only team.manage / billing.manage). Policy methods
            // (view, viewAny, create, update, ...) delegate to these via
            // $user->can('invoices.manage') etc., so they must fall through
            // and abstain here — otherwise every policy check on a scoped
            // token would be denied, since a token never holds "view" or
            // "create" as an ability of its own.
            if (! AbilityCatalog::handles($ability) && ! in_array($ability, ['team.manage', 'billing.manage'], true)) {
                return null;
            }

            $token = $user->currentAccessToken();

            // Not token auth (first-party session) or a full-access login/2FA
            // token (default '*' ability): nothing to scope here.
            if ($token === null || $token->can('*')) {
                return null;
            }

            // A scoped personal access token may only exercise the abilities it
            // was granted. This deliberately covers the SaaS-only team.manage /
            // billing.manage too — they're never grantable to a token
            // (CreateTokenData restricts to AbilityCatalog) and so must be
            // denied here rather than slip through to the owner's role-based
            // grant further down the chain.
            return $token->can($ability) ? null : false;
        });

        // The OSS edition has no roles — grant every core ability and leave
        // data isolation to HasUserScope and the policies' account checks.
        if (config('qasa.edition') === 'oss') {
            Gate::before(fn (User $user, string $ability) => AbilityCatalog::handles($ability) ? true : null);
        }
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/auth.php');

        // personal_access_tokens is tenant-scoped (phase 7,
        // docs/plans/POSTGRES_RLS_PLAN.md) — resolution needs to bind the
        // connection before the stock lookup can see the row at all. Core,
        // not Saas-only: OSS accounts have personal_access_tokens too, and
        // registering this here is what makes Admin's own tokens (a
        // different module entirely) resolve correctly as well, via the
        // carve-out its migration adds to the same policy.
        Sanctum::usePersonalAccessTokenModel(TenantAwarePersonalAccessToken::class);

        if ($this->app->runningInConsole()) {
            $this->commands([CreateUserCommand::class]);
        }

        // Single source of truth for how strong a *new* password must be —
        // referenced via Password::defaults() by register, profile update and
        // reset. uncompromised() (HIBP k-anonymity) only in production so the
        // test/local suites never depend on an outbound call.
        Password::defaults(function (): Password {
            $rule = Password::min(10)->letters()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // Reset links land on the SPA, which posts the token back to the API.
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return rtrim((string) config('app.frontend_url'), '/')
                .'/reset-password?token='.$token
                .'&email='.urlencode($user->email);
        });
    }
}
