<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Middleware;

use App\Modules\Shared\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds a public document link to the account that issued it.
 *
 * These routes serve an invoice or quote to whoever holds its link, so nobody
 * is authenticated and nothing has bound the connection. Under Row Level
 * Security that means the lookup finds nothing and every link 404s.
 *
 * Resolving the owner is therefore a deliberate step outside the policy,
 * through a SECURITY DEFINER function that takes a token and returns nothing
 * but an account id. It runs on the same connection, so it sees the same
 * transaction — a lookup on the owner connection could not, which is what
 * every test runs inside. Once bound, the controller runs entirely inside the
 * policy, so a link can only ever reach its own account's data.
 *
 * An unknown token leaves the connection unbound, and the controller's
 * firstOrFail turns that into the 404 it always was.
 */
class BindPublicDocumentTenant
{
    public function handle(Request $request, Closure $next, string $table): Response
    {
        $token = $request->route('token');

        if (is_string($token) && $token !== '') {
            $owner = DB::selectOne(
                'SELECT public.account_for_public_document(?, ?) AS account_id',
                [$table, $token],
            );

            if ($owner?->account_id !== null) {
                TenantContext::set((string) $owner->account_id);
            }
        }

        return $next($request);
    }
}
