<?php

use App\Modules\Auth\Domain\Exceptions\CaptchaRequiredException;
use App\Modules\Auth\Domain\Exceptions\TooManyLoginAttemptsException;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Exceptions\ExpectedIntegrationFailure;
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
use Illuminate\Http\Request;
use Sentry\Laravel\Integration as SentryIntegration;

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
        // See config/cloudflare.php — off by default, so this is a no-op
        // until a deployment is actually behind Cloudflare's proxy. Reads the
        // config file directly (like the edition overlay require() below)
        // rather than via the config() helper: this closure also runs while
        // resolving the Console Kernel during `composer install`'s
        // package:discover step, before the container has a 'config' binding.
        $cloudflare = require __DIR__.'/../config/cloudflare.php';
        if ($cloudflare['proxy_enabled']) {
            $middleware->trustProxies(
                at: $cloudflare['trusted_proxies'],
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO,
            );
        }

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
        // Everything that survives the dontReport rules below goes to Sentry
        // as well as to the log. Inert without SENTRY_LARAVEL_DSN, which is
        // how the OSS build and the test suite get away with never thinking
        // about it — see docs/plans/SENTRY_OBSERVABILITY_PLAN.md.
        SentryIntegration::handles($exceptions);

        // A rule saying no is an answer, not a fault. Reporting these filled
        // the log with "the client has no e-mail address" and "SEPA export
        // requires an IBAN", each with a stack trace — 28 MB of them in
        // development, and the reason a real fault would be invisible in
        // production. The 422 below is what tells the caller; nobody is
        // paged for it.
        $exceptions->dontReport(DomainException::class);

        // The same judgement one level out. An integration failure can be our
        // bug or it can be a fact about the tenant's account — a revoked bank
        // token, a BYOK key the provider stopped accepting, an access point
        // that does not do inbound at all. Those classes carry both kinds in
        // one type, so the instance is asked rather than the class name; see
        // ExpectedIntegrationFailure. Still logged, just never paged for.
        $exceptions->dontReportWhen(
            fn (Throwable $e): bool => $e instanceof ExpectedIntegrationFailure && $e->isExpected()
        );

        // Ahead of the DomainException rule below for the same reason as the
        // one under it: same 422, but the client cannot act on it without
        // the flag telling it to render a captcha rather than an error.
        $exceptions->renderable(
            fn (CaptchaRequiredException $e) => response()->json([
                'message' => $e->getMessage(),
                'captcha_required' => true,
            ], 422)
        );

        // Ahead of the DomainException rule below, which is its parent and
        // would otherwise answer for it: render callbacks are matched in
        // registration order and the first hit wins. A login backoff is a
        // wait rather than a rejected value, so it needs the 429 and the
        // header saying how long — see TooManyLoginAttemptsException.
        $exceptions->renderable(
            fn (TooManyLoginAttemptsException $e) => response()
                ->json(['message' => $e->getMessage()], 429)
                ->header('Retry-After', (string) $e->retryAfterSeconds)
        );

        // Business-rule violations always surface as 422 JSON — the single
        // error convention across all modules. Controllers may still catch
        // earlier for a custom payload; this is the safety net.
        $exceptions->renderable(
            fn (DomainException $e) => response()->json(['message' => $e->getMessage()], 422)
        );
    })->create();
