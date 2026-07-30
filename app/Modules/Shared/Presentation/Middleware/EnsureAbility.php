<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * OSS core default for the `permission:` alias.
 *
 * The SaaS overlay maps this alias to spatie's PermissionMiddleware, which
 * needs roles the OSS edition does not have. Here the check goes through the
 * Gate instead: AuthServiceProvider grants every AbilityCatalog ability to
 * the single account owner, while a scoped API token still narrows what it
 * may do — so a token's scope is enforced in both editions.
 */
class EnsureAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        foreach ($abilities as $ability) {
            if ($user === null || $user->cannot($ability)) {
                throw new AccessDeniedHttpException;
            }
        }

        return $next($request);
    }
}
