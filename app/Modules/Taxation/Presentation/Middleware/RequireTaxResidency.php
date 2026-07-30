<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Middleware;

use App\Modules\Auth\Domain\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates document-creation and paid-plan routes on completed tax residency
 * (step 2). Every account starts with country = null, so this is the
 * middleware that actually enforces the "residency is mandatory" rule.
 *
 * Returns the 409 directly (not via DomainException, which always renders
 * as 422 — see bootstrap/app.php) — same idiom as the other route-level
 * gates in this codebase (EnsureAdminRole, EnsureMemberSeatActive).
 */
final class RequireTaxResidency
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if ($user instanceof User && ! $user->hasTaxResidency()) {
            return response()->json(['message' => __('taxation.residency_required')], 409);
        }

        return $next($request);
    }
}
