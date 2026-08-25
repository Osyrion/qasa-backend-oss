<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Middleware;

use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the connection to a Sanctum-authenticated account, globally and
 * early — before any route-specific middleware, including `auth` itself.
 *
 * `Authenticate` (the `auth` alias) already does this, but only on routes
 * that require it, and only after everything ahead of it in the pipeline has
 * run. That was enough until users itself became tenant-scoped (phase 7,
 * docs/plans/POSTGRES_RLS_PLAN.md): the SaaS edition's own global middleware
 * — EnsureMemberSeatActive, EnsureApiTokenFeatureAccess — reads
 * $request->user('sanctum') and then traverses a team member's owner()
 * relation *before* any route middleware runs, and that relation is now
 * behind the same policy as everything else. Binding here, ahead of them,
 * is what makes that read see the row.
 *
 * Resolving the guard is what actually finds the token and, via
 * TenantAwarePersonalAccessToken::findToken(), does the equivalent bind for
 * the row lookup itself — this only carries that result the rest of the
 * way to accountOwnerId(). Laravel's guard memoizes the resolved user for
 * the rest of the request, so calling it here does not cost a second query
 * wherever `auth` or the SaaS middleware call it again.
 *
 * AdminUser authenticates through its own guard and owns no account, so the
 * instanceof leaves admin requests unbound rather than acting as some tenant.
 */
class BindAuthenticatedTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if ($user instanceof Account) {
            TenantContext::set($user->accountOwnerId());
        }

        return $next($request);
    }
}
