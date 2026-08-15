<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `ENGINEERING_GUARDRAILS_PLAN.md` part A1: every route in `api/v1/*` must
 * require authentication, and every authenticated route must go through a
 * Policy check, a `permission:`/`admin.role:` middleware, or a reviewed,
 * justified allowlist entry — the same discipline TenantScopeTest already
 * applies to models, moved one layer up to the routes that reach them. A
 * route registered without `auth:sanctum` (or the admin guard's
 * `admin.access`) is a silent unauthenticated endpoint; a route that has
 * authentication but no authorization path lets any authenticated tenant
 * reach another tenant's data unless something else (global scope + RLS)
 * happens to save it — exactly the "silent no-op bug" CLAUDE.md warns about.
 *
 * Needs the app booted (real route list, real controller classes for the
 * reflection-based authorize() check) — same reasoning as TenantScopeTest
 * for opting into TestCase here instead of Pest.php's default `uses()`.
 */
uses(TestCase::class);

/**
 * Route symbols intentionally reachable under api/v1 without
 * auth:sanctum/admin.access. Keyed by route name where the route has one,
 * otherwise by "METHOD uri" (the vendor MCP package registers its 405 stub
 * routes unnamed). A route missing from here — and missing an auth
 * middleware — fails the build instead of shipping as a silent
 * unauthenticated endpoint.
 *
 * Premium-module routes live in premiumPublicRouteAllowlist()
 * (tests/Pest.edition.php) — this file survives the OSS build, where those
 * routes do not exist and would trip the stale-entry guard below. Same
 * mechanism as TenantScopeTest's premiumTenantScopeAllowlist().
 *
 * @return array<string, string>
 */
function publicRouteAllowlist(): array
{
    return [
        ...(function_exists('premiumPublicRouteAllowlist') ? premiumPublicRouteAllowlist() : []),

        // Pre-login: there is no session yet to authenticate. Throttled at
        // the route/group level against brute force and enumeration.
        'auth.register' => 'pre-login',
        'auth.login' => 'pre-login',
        'auth.google.redirect' => 'pre-login OAuth kickoff',
        'auth.google.callback' => 'pre-login OAuth exchange',
        'auth.password.email' => 'pre-login password reset request',
        'auth.password.reset' => 'pre-login password reset completion, proven by the emailed token',
        // Completes a login stuck at the 2FA challenge with a short-lived
        // challenge token, not a bearer token.
        'auth.2fa.verify' => 'pre-login 2FA challenge, proven by the challenge token',
        // Signed link from the verification e-mail — the signature is the proof.
        'verification.verify' => 'signed link, no bearer token involved',

        // Public tokenized documents — proven by the document's own opaque
        // token (public.document: middleware), not a bearer token.
        'public.invoices.show' => 'proven by the document token',
        // Client portal: proven by the client's own opaque portal token
        // (BindClientPortalTenant binds the account, the controller narrows
        // to that one client), not by a bearer token.
        'portal.show' => 'proven by the client portal token',
        'portal.invoices.index' => 'proven by the client portal token',
        'portal.quotes.index' => 'proven by the client portal token',
        'portal.invoices.pdf' => 'proven by the client portal token',
        'public.invoices.pdf' => 'proven by the document token',
        'public.quotes.show' => 'proven by the document token',
        'public.quotes.pdf' => 'proven by the document token',
        'public.quotes.accept' => 'proven by the document token',
        'public.quotes.reject' => 'proven by the document token',

        // laravel/mcp's Registrar::web() registers GET/DELETE stubs that only
        // ever return a static 405 "use POST" response — they never reach the
        // server or any tenant data, so they carry no middleware at all by
        // design. The POST route (the one that actually runs tools) does
        // carry auth:sanctum and is checked like any other route below.
        'GET api/v1/mcp/qasa' => '405 stub, never touches the server or tenant data',
        'DELETE api/v1/mcp/qasa' => '405 stub, never touches the server or tenant data',
    ];
}

/**
 * Authenticated route symbols that intentionally have no `permission:`/
 * `admin.role:` middleware and no `$this->authorize()`/`authorizeResource()`
 * call, because the action structurally cannot reach another tenant's data:
 * it only ever touches the caller's own singleton account state (no foreign
 * id in the URI), lists/creates records that HasUserScope's global scope and
 * RLS already confine to the caller, or scopes explicitly by owner inside
 * the query (`->forUser($owner)`, `$user->tokens()->where(...)`) instead of
 * through a Policy — a pattern this file can't reliably detect statically,
 * so it is recorded here instead of trusted silently.
 *
 * An entry here answers the *tenancy* question and only that one. It is not
 * a statement that the route needs no authorization: a route on this list is
 * reachable by every role in PermissionCatalog::matrix(), including Viewer,
 * and by an API token scoped to a completely unrelated ability, because
 * nothing on the path ever asks the Gate anything. So a route belongs here
 * only when both of those are genuinely fine — which in practice means it
 * reads or writes the caller's own identity, session or preferences.
 *
 * Anything that touches account-wide business data (money, documents,
 * aggregates over them, integration credentials) fails that second test even
 * when it passes the first, and needs a Policy or `permission:` gate instead.
 * That distinction is what let statistics, the VAT reports, expenses,
 * exchange rates and the tax-return wizard sit here for a while with an
 * accurate reason attached and no authorization at all; the sweep in
 * tests/Feature/Auth/ScopedTokenCoverageTest.php is the standing check.
 *
 * Premium-module routes live in premiumOwnAccountRouteAllowlist()
 * (tests/Pest.edition.php) — this file survives the OSS build, where those
 * routes do not exist and would trip the stale-entry guard below. Same
 * mechanism as TenantScopeTest's premiumTenantScopeAllowlist().
 *
 * @return array<string, string>
 */
function ownAccountRouteAllowlist(): array
{
    return [
        ...(function_exists('premiumOwnAccountRouteAllowlist') ? premiumOwnAccountRouteAllowlist() : []),

        // Own profile/session/security — every action reads or mutates
        // auth()->user() itself, never another id.
        'auth.me' => 'own user record',
        'auth.logout' => 'own session',
        'auth.profile.update' => 'own user record',
        'auth.profile.logo' => 'own user record',
        'auth.profile.export' => 'own user record',
        'auth.profile.setup-status' => 'own user record',
        'auth.profile.delete' => 'own user record',
        'auth.profile.accept-terms' => 'own user record',
        'dashboard' => 'own-account aggregate, no foreign id',
        'verification.send' => 'own user record (resends own verification e-mail)',
        'auth.2fa.enable' => 'own user record',
        'auth.2fa.confirm' => 'own user record',
        'auth.2fa.disable' => 'own user record',
        'auth.2fa.recovery-codes' => 'own user record',
        // Explicitly scoped via $user->tokens()->where('id', ...) in the
        // controller, not through a Policy.
        'auth.tokens.index' => 'own user record',
        'auth.tokens.store' => 'creates a token owned by the caller',
        'auth.tokens.destroy' => "scoped via \$user->tokens()->where('id', ...) in the controller",
        // Same reasoning as auth.tokens.* above — SessionController scopes
        // via $user->tokens()->where('type', 'session') in the controller.
        'auth.sessions.index' => 'own user record',
        'auth.sessions.destroy-others' => 'scoped via $user->tokens() in the controller',
        'auth.sessions.destroy' => "scoped via \$user->tokens()->where('id', ...) in the controller",

        // Personal preference, not an account setting — each caller reads/
        // writes only their own row (see NotificationPreferencesController).
        'notifications.preferences.show' => 'own user record',
        'notifications.preferences.update' => 'own user record',

        // Own tax residency — one row per account, no foreign id. The
        // tax-return endpoints used to live here on the same reasoning and
        // now carry permission:taxation.* instead: "no foreign id" answers
        // the tenancy question, and nothing else.
        'auth.complete-residency' => 'own account, singleton',
        'auth.registry-lookup' => 'reads the public business registry, not tenant data',

        // BYOK AI credentials: {provider} is an enum string (openai/…), not a
        // foreign row id — one credential per account per provider, resolved
        // and scoped explicitly by user_id in ByokCredentialResolver (see
        // TenantScopeTest's AiCredential allowlist entry for the same model).
        'ai-credentials.index' => 'own account, keyed by provider enum',
        'ai-credentials.upsert' => 'own account, keyed by provider enum',
        'ai-credentials.destroy' => 'own account, keyed by provider enum',
        'ai-credentials.test' => 'own account, keyed by provider enum',

        // The AI Act art. 50 transparency notice for the caller's own
        // account: reads config plus that account's own switches, exposes no
        // business data and no secret, and every team member is entitled to
        // read it — gating it behind a permission would be the wrong answer
        // to "what does this app send to a model about me".
        'ai.transparency' => 'own account, no foreign id — config and the account\'s own AI switches',

        // MCP tools resolve and scope tenant data from the authenticated
        // user's own context internally — there is no route-bound foreign id.
        'POST api/v1/mcp/qasa' => 'tools scope all data access via the authenticated user() context',
    ];
}

/**
 * Route symbols under api/v1 intentionally registered without any throttle.
 *
 * A rate limit is not only a brute-force control: past authentication it is
 * what stops one caller — a runaway client, a leaked token, an admin session
 * driving an export that reads across every account — from consuming the
 * whole box. The back office had none at all on 28 routes while the login in
 * front of it was throttled twice over, which is the shape this guard exists
 * to catch: the protection stops exactly where the route file changes.
 *
 * @return array<string, string>
 */
function unthrottledRouteAllowlist(): array
{
    return [
        // laravel/mcp's Registrar::web() 405 stubs — see publicRouteAllowlist()
        // above for why these carry no middleware at all by design.
        'GET api/v1/mcp/qasa' => '405 stub, never reaches the server or tenant data',
        'DELETE api/v1/mcp/qasa' => '405 stub, never reaches the server or tenant data',
    ];
}

/**
 * @return array<string, RoutingRoute>
 */
function apiV1Routes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        $routes[routeKey($route)] = $route;
    }

    return $routes;
}

function routeKey(RoutingRoute $route): string
{
    if ($route->getName() !== null) {
        return $route->getName();
    }

    $methods = implode('|', array_values(array_diff($route->methods(), ['HEAD'])));

    return "{$methods} {$route->uri()}";
}

/**
 * @param  array<int, string>  $middleware
 */
function hasAuthenticationMiddleware(array $middleware): bool
{
    foreach ($middleware as $entry) {
        if ($entry === 'auth:sanctum' || $entry === 'admin.access' || str_starts_with($entry, 'auth:')) {
            return true;
        }
    }

    return false;
}

/**
 * @param  array<int, string>  $middleware
 */
function hasAuthorizationMiddleware(array $middleware): bool
{
    foreach ($middleware as $entry) {
        if (str_starts_with($entry, 'permission:') || str_starts_with($entry, 'admin.role:')) {
            return true;
        }
    }

    return false;
}

/**
 * @param  array<int, string>  $middleware
 */
function hasThrottleMiddleware(array $middleware): bool
{
    foreach ($middleware as $entry) {
        if ($entry === 'throttle' || str_starts_with($entry, 'throttle:')) {
            return true;
        }
    }

    return false;
}

/**
 * @param  array<int, string>  $middleware
 */
function hasAdminGuardMiddleware(array $middleware): bool
{
    return in_array('admin.access', $middleware, true);
}

/**
 * Best-effort static check for whether a route's controller action already
 * enforces a Policy: an explicit `$this->authorize()`/`authorizeResource()`
 * call in the action's own method body, or `authorizeResource()` in the
 * constructor covering one of Laravel's standard resource actions. A method
 * that scopes by owner some other way (explicit `->forUser()`/`->where()`
 * scoping) is not detected here and belongs in ownAccountRouteAllowlist()
 * instead, with a reason a reviewer can check.
 */
function controllerActionAuthorizes(RoutingRoute $route): bool
{
    $action = $route->getActionName();

    if (str_contains($action, '@')) {
        [$class, $method] = explode('@', $action, 2);
    } else {
        $class = $action;
        $method = '__invoke';
    }

    if (! class_exists($class)) {
        return false;
    }

    $reflection = new ReflectionClass($class);

    if (! $reflection->hasMethod($method)) {
        return false;
    }

    if (methodBodyContainsAuthorizeCall($reflection->getMethod($method))) {
        return true;
    }

    $standardResourceActions = ['index', 'show', 'create', 'store', 'edit', 'update', 'destroy'];

    if (in_array($method, $standardResourceActions, true) && $reflection->hasMethod('__construct')) {
        return methodBodyContainsAuthorizeCall($reflection->getMethod('__construct'), 'authorizeResource(');
    }

    return false;
}

function methodBodyContainsAuthorizeCall(ReflectionMethod $method, string $needle = '->authorize('): bool
{
    $file = $method->getFileName();

    if ($file === false) {
        return false;
    }

    $lines = file($file);

    if ($lines === false) {
        return false;
    }

    $start = $method->getStartLine() - 1;
    $length = $method->getEndLine() - $method->getStartLine() + 1;
    $body = implode('', array_slice($lines, $start, $length));

    return str_contains($body, $needle) || str_contains($body, 'authorizeResource(');
}

it('requires an authentication middleware on every api/v1 route unless explicitly public', function (): void {
    $public = publicRouteAllowlist();
    $missing = [];

    foreach (apiV1Routes() as $key => $route) {
        if (array_key_exists($key, $public)) {
            continue;
        }

        if (! hasAuthenticationMiddleware($route->gatherMiddleware())) {
            $missing[] = $key;
        }
    }

    expect($missing)->toBe(
        [],
        'These api/v1 routes have no authentication middleware — add auth:sanctum/admin.access, '.
        'or justify them in publicRouteAllowlist(): '.implode(', ', $missing),
    );
});

it('does not leave a stale entry in the public route allowlist', function (): void {
    $existing = apiV1Routes();
    $stale = [];

    foreach (publicRouteAllowlist() as $key => $reason) {
        if (! array_key_exists($key, $existing)) {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([], 'Stale publicRouteAllowlist entries (route renamed/removed): '.implode(', ', $stale));
});

it('requires an authorization path on every authenticated api/v1 route', function (): void {
    $public = publicRouteAllowlist();
    $ownAccount = ownAccountRouteAllowlist();
    $missing = [];

    foreach (apiV1Routes() as $key => $route) {
        if (array_key_exists($key, $public) || array_key_exists($key, $ownAccount)) {
            continue;
        }

        if (hasAuthorizationMiddleware($route->gatherMiddleware())) {
            continue;
        }

        if (controllerActionAuthorizes($route)) {
            continue;
        }

        $missing[] = $key;
    }

    expect($missing)->toBe(
        [],
        'These authenticated api/v1 routes have no permission/admin.role middleware and no detected '.
        'authorize() call — add one, or justify in ownAccountRouteAllowlist(): '.implode(', ', $missing),
    );
});

it('does not leave a stale entry in the own-account route allowlist', function (): void {
    $existing = apiV1Routes();
    $stale = [];

    foreach (ownAccountRouteAllowlist() as $key => $reason) {
        if (! array_key_exists($key, $existing)) {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([], 'Stale ownAccountRouteAllowlist entries (route renamed/removed): '.implode(', ', $stale));
});

it('requires a throttle on every api/v1 route', function (): void {
    $allowed = unthrottledRouteAllowlist();
    $missing = [];

    foreach (apiV1Routes() as $key => $route) {
        if (array_key_exists($key, $allowed)) {
            continue;
        }

        if (! hasThrottleMiddleware($route->gatherMiddleware())) {
            $missing[] = $key;
        }
    }

    expect($missing)->toBe(
        [],
        'These api/v1 routes carry no throttle — add one (throttle:api, or a named limiter), '.
        'or justify them in unthrottledRouteAllowlist(): '.implode(', ', $missing),
    );
});

it('does not leave a stale entry in the unthrottled route allowlist', function (): void {
    $existing = apiV1Routes();
    $stale = [];

    foreach (unthrottledRouteAllowlist() as $key => $reason) {
        if (! array_key_exists($key, $existing)) {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([], 'Stale unthrottledRouteAllowlist entries (route renamed/removed): '.implode(', ', $stale));
});

/*
 * The enrollment wall is only as good as its weakest route group. It has no
 * allowlist on purpose: `admin.2fa` exempts the enrollment and session routes
 * *inside* the middleware (RequireAdminTwoFactor::ENROLLMENT_ROUTES), and the
 * two pre-login routes — admin.auth.login and admin.auth.2fa.verify — carry no
 * `admin.access` either, so they never reach this check. Every route that has
 * passed the admin guard must therefore also have passed the wall.
 *
 * AdminTwoFactorEnforcementTest proves the wall *works* against a hand-written
 * list of paths; that list is what let the Subscriptions module's own admin
 * group ship without `admin.2fa`, leaving an un-enrolled admin able to confirm
 * subscription payments and rewrite plan prices. This derives the list from the
 * route table instead, so a new admin route group cannot repeat it.
 */
it('requires admin.2fa on every route behind the admin guard', function (): void {
    $missing = [];

    foreach (apiV1Routes() as $key => $route) {
        $middleware = $route->gatherMiddleware();

        if (! hasAdminGuardMiddleware($middleware)) {
            continue;
        }

        if (! in_array('admin.2fa', $middleware, true)) {
            $missing[] = $key;
        }
    }

    expect($missing)->toBe(
        [],
        'These routes sit behind admin.access without admin.2fa, so an un-enrolled admin reaches them: '
        .implode(', ', $missing),
    );
});

/*
 * Route parameters reach a controller action positionally, so a path segment
 * the action does not declare is not merely ignored — it shifts every later
 * parameter one slot left. `clients/{client}/contact-persons/{contactPerson}`
 * declared only the contact person, so the client id landed in its slot and
 * PUT/DELETE died with a TypeError on every call. It shipped that way because
 * a nested route reads correct at a glance and nothing tested those two verbs.
 *
 * Declaring every segment is also the precondition for scoping the child
 * through its parent, whether via scopeBindings() or an explicit check.
 */
it('declares every path parameter in the controller action signature', function (): void {
    $undeclared = [];

    foreach (apiV1Routes() as $key => $route) {
        preg_match_all('/\{(\w+)\??}/', $route->uri(), $matches);

        if ($matches[1] === []) {
            continue;
        }

        $declared = array_map(
            static fn (ReflectionParameter $parameter): string => Str::snake($parameter->getName()),
            $route->signatureParameters(),
        );

        $missing = array_values(array_diff(
            array_map(static fn (string $name): string => Str::snake($name), $matches[1]),
            $declared,
        ));

        if ($missing !== []) {
            $undeclared[] = $key.' ({'.implode('}, {', $missing).'})';
        }
    }

    expect($undeclared)->toBe(
        [],
        'These routes have a path parameter their action does not declare, which shifts the '.
        'remaining arguments out of position: '.implode(', ', $undeclared),
    );
});

it('does not allowlist a route that already carries permission/admin.role middleware', function (): void {
    $existing = apiV1Routes();
    $stale = [];

    foreach (array_keys(ownAccountRouteAllowlist()) as $key) {
        $route = $existing[$key] ?? null;

        if ($route !== null && hasAuthorizationMiddleware($route->gatherMiddleware())) {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([], 'These ownAccountRouteAllowlist entries now carry permission/admin.role middleware — remove them: '.implode(', ', $stale));
});
