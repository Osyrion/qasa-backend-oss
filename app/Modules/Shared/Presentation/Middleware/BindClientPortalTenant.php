<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Middleware;

use App\Modules\Shared\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds a client-portal link to the account that issued it — the same
 * arrangement as BindPublicDocumentTenant, for the same reason.
 *
 * The portal serves a client who is not a user of this system, so nothing
 * authenticates and nothing binds the connection; under Row Level Security
 * that means every lookup finds nothing. Resolving the owner is a deliberate
 * step outside the policy, through a SECURITY DEFINER function that accepts
 * a token and returns nothing but an account id.
 *
 * Binding the account is only half the answer: the policy scopes to the
 * *account*, and a portal link must reach exactly one *client* inside it.
 * That second narrowing is the controller's, and it is not optional.
 *
 * An unknown or revoked token leaves the connection unbound, which the
 * controller's firstOrFail turns into the 404 it should be — no distinction
 * between "wrong token" and "no such client".
 */
class BindClientPortalTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');

        if (is_string($token) && $token !== '') {
            $owner = DB::selectOne(
                'SELECT public.account_for_client_portal(?) AS account_id',
                [$token],
            );

            if ($owner?->account_id !== null) {
                TenantContext::set((string) $owner->account_id);
            }
        }

        return $next($request);
    }
}
