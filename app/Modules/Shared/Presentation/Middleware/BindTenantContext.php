<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Middleware;

use App\Modules\Shared\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clears the connection's account binding at the start of every request.
 *
 * Deliberately only clears. Binding happens on the Authenticated event, once
 * a guard has actually resolved a user — which under `auth:sanctum` is route
 * middleware and so runs after this. Splitting it that way means the default
 * state of any connection is "no account", and a request that never
 * authenticates never acquires one.
 *
 * Prepended, so it runs before anything that might query.
 */
class BindTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        TenantContext::clear();

        return $next($request);
    }

    /**
     * A request handed back a connection still bound to its account would let
     * the next request on that connection inherit it. Under PHP-FPM the
     * connection usually dies with the request; under Octane or a persistent
     * setup it does not.
     */
    public function terminate(Request $request, Response $response): void
    {
        TenantContext::clear();
    }
}
