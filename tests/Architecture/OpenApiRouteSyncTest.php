<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `ENGINEERING_GUARDRAILS_PLAN.md` part A4: every registered `api/v1/*`
 * route has an OpenAPI (L5-Swagger) annotation, and no annotated path exists
 * without a matching route.
 *
 * Regenerates storage/api-docs/api-docs.json fresh (Artisan::call, not the
 * committed file) so a stale docs artifact can't hide a real drift.
 *
 * Comparison is normalized on two axes, both structural rather than
 * pedantic — this test is about presence, not naming style:
 *  - every `{paramName}` segment collapses to `{}`, since an OA annotation's
 *    placeholder name commonly differs from the route's own (e.g. the route
 *    binds `{client}`, the doc says `{id}`) without the endpoint being any
 *    less documented;
 *  - PATCH and PUT are treated as the same method. Route::apiResource()
 *    registers both for every `update` action pointing at the same
 *    controller method, so a doc that only mentions one of the two (either
 *    direction — this codebase has both) already documents both routes.
 *
 * Found and fixed a real, substantial gap while building this: the entire
 * Admin module's auth/user/subscription-order management (AdminAuthController,
 * AdminUserController, AdminSubscriptionOrderController) plus
 * DashboardController had zero OpenAPI annotations — about 14 endpoints.
 * Annotated all of them (including a new `AdminUser` component schema)
 * rather than allowlisting, since admin tooling is exactly the kind of
 * surface an integrator/support engineer reaches for the docs to understand.
 *
 * Needs the app booted (real route list, Artisan, storage_path()) — same
 * reasoning as RoutePermissionCoverageTest for opting into TestCase here.
 */
uses(TestCase::class);

/**
 * Route symbols intentionally undocumented — vendor package internals with
 * no meaningful OpenAPI shape of their own, not application endpoints.
 *
 * @return array<string, string>
 */
function undocumentedRouteAllowlist(): array
{
    return [
        // laravel/mcp's streamable-HTTP transport: GET/DELETE are static 405
        // stubs (see RoutePermissionCoverageTest's publicRouteAllowlist for
        // the same routes), and POST is the MCP JSON-RPC protocol itself —
        // self-describing via MCP's own tool/resource listing, not REST.
        'GET api/v1/mcp/qasa' => 'laravel/mcp transport, self-describing via the MCP protocol, not REST',
        'DELETE api/v1/mcp/qasa' => 'laravel/mcp transport, self-describing via the MCP protocol, not REST',
        'POST api/v1/mcp/qasa' => 'laravel/mcp transport, self-describing via the MCP protocol, not REST',
    ];
}

/**
 * @return array<string, string> normalized "METHOD /path" => route key
 */
function normalizedRouteMap(): array
{
    $map = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RoutingRoute $route */
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        $key = $route->getName() ?? implode('|', array_values(array_diff($route->methods(), ['HEAD']))).' '.$route->uri();

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $map[normalizedPathKey($method, '/'.$route->uri())] = $key;
        }
    }

    return $map;
}

/**
 * @return list<string> normalized "METHOD /path" entries from the freshly
 *                      generated OpenAPI spec
 */
function normalizedSpecPaths(): array
{
    Artisan::call('l5-swagger:generate');

    /** @var array{paths: array<string, array<string, mixed>>} $spec */
    $spec = json_decode((string) file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);

    $httpMethods = ['get', 'post', 'put', 'patch', 'delete', 'options'];
    $paths = [];

    foreach ($spec['paths'] as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            if (! in_array($method, $httpMethods, true)) {
                continue;
            }

            $paths[] = normalizedPathKey($method, $path);
        }
    }

    return $paths;
}

function normalizedPathKey(string $method, string $path): string
{
    $method = strtoupper($method);
    $method = $method === 'PATCH' ? 'PUT' : $method;
    $path = preg_replace('/\{[^}]+\}/', '{}', $path);

    return "{$method} {$path}";
}

it('has an OpenAPI annotation for every registered api/v1 route, unless justified', function (): void {
    $allowlist = undocumentedRouteAllowlist();
    $routes = normalizedRouteMap();
    $specPaths = array_flip(normalizedSpecPaths());

    $missing = [];

    foreach ($routes as $normalized => $routeKey) {
        if (array_key_exists($routeKey, $allowlist) || isset($specPaths[$normalized])) {
            continue;
        }

        $missing[] = "{$routeKey} ({$normalized})";
    }

    expect($missing)->toBe(
        [],
        'These routes have no OpenAPI annotation — add one, or justify in undocumentedRouteAllowlist(): '.implode(', ', $missing),
    );
});

it('does not document a path that has no matching route', function (): void {
    $routes = array_flip(array_keys(normalizedRouteMap()));
    $specPaths = normalizedSpecPaths();

    $orphaned = [];

    foreach (array_unique($specPaths) as $normalized) {
        if (! isset($routes[$normalized])) {
            $orphaned[] = $normalized;
        }
    }

    expect($orphaned)->toBe([], 'These OpenAPI paths have no matching registered route: '.implode(', ', $orphaned));
});

it('does not allowlist a route symbol that no longer exists', function (): void {
    $existing = normalizedRouteMap();
    $stale = [];

    foreach (array_keys(undocumentedRouteAllowlist()) as $routeKey) {
        if (! in_array($routeKey, $existing, true)) {
            $stale[] = $routeKey;
        }
    }

    expect($stale)->toBe([], 'Stale undocumentedRouteAllowlist entries (route renamed/removed): '.implode(', ', $stale));
});
