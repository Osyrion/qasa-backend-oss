<?php

use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Presentation\Middleware\AllowAllFeatures;
use App\Modules\Shared\Presentation\Middleware\Authenticate;
use App\Modules\Shared\Presentation\Middleware\BindAuthenticatedTenant;
use App\Modules\Shared\Presentation\Middleware\BindPublicDocumentTenant;
use App\Modules\Shared\Presentation\Middleware\BindTenantContext;
use App\Modules\Shared\Presentation\Middleware\EnsureAbility;
use App\Modules\Shared\Presentation\Middleware\IdempotencyKey;
use App\Modules\Shared\Presentation\Middleware\RequestId;
use App\Modules\Shared\Presentation\Middleware\SetLocale;
use App\Modules\Taxation\Presentation\Middleware\RequireTaxResidency;
use App\Providers\AppServiceProvider;
use App\Providers\TelescopeServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        AppServiceProvider::class,
        TelescopeServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
        $middleware->prepend(BindTenantContext::class);
        $middleware->append(SetLocale::class);

        // Ahead of the edition overlay's own global middleware on purpose —
        // see BindAuthenticatedTenant's docblock for why that ordering
        // matters now that users is tenant-scoped.
        $middleware->append(BindAuthenticatedTenant::class);

        // Middleware::alias() overwrites its whole array on each call rather
        // than merging, so every alias across core and the edition overlay
        // is collected into one array and registered with a single call.
        // `feature:` and `permission:` are used by core route files, so both
        // need a core default — otherwise the OSS build has routes pointing
        // at an undefined alias and 500s at dispatch. The SaaS overlay below
        // replaces them with RequiresFeature and spatie's middleware.
        $aliases = [
            // Laravel's own `auth`, extended to bind the database connection
            // to the authenticated account — the only point that knows
            // authentication succeeded. See Middleware\Authenticate.
            'auth' => Authenticate::class,
            'idempotent' => IdempotencyKey::class,
            'public.document' => BindPublicDocumentTenant::class,
            'residency.required' => RequireTaxResidency::class,
            'feature' => AllowAllFeatures::class,
            'permission' => EnsureAbility::class,
        ];

        // Edition overlay (SaaS repo only) — spatie middleware aliases.
        if (file_exists(__DIR__.'/middleware.edition.php')) {
            (require __DIR__.'/middleware.edition.php')($middleware, $aliases);
        }

        $middleware->alias($aliases);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Business-rule violations always surface as 422 JSON — the single
        // error convention across all modules. Controllers may still catch
        // earlier for a custom payload; this is the safety net.
        $exceptions->renderable(
            fn (DomainException $e) => response()->json(['message' => $e->getMessage()], 422)
        );
    })->create();
