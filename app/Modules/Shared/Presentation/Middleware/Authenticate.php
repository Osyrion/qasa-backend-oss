<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Middleware;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Infrastructure\Sentry\SentryContext;
use App\Modules\Shared\Support\TenantContext;
use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;
use Illuminate\Http\Request;

/**
 * Laravel's `auth` middleware, plus binding the connection to the account.
 *
 * This is the one place that knows authentication has actually succeeded and
 * for which guard. The obvious hook, the Authenticated event, is dispatched
 * only by SessionGuard — this API authenticates through Sanctum's token
 * guard, where it never fires at all.
 *
 * AdminUser authenticates through its own guard and owns no account, so the
 * instanceof leaves admin requests unbound rather than acting as some tenant.
 */
class Authenticate extends BaseAuthenticate
{
    /**
     * @param  Request  $request
     * @param  array<int, string|null>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        parent::authenticate($request, $guards);

        // Via the default guard explicitly: $this->auth is an Auth\Factory,
        // which has no user() of its own — it only works by AuthManager
        // forwarding unknown calls to guard().
        $user = $this->auth->guard()->user();

        if ($user instanceof User) {
            TenantContext::set($user->accountOwnerId());

            // Same reason the tenant bind lives here: this is where the
            // identity is first known. An event without it cannot answer
            // whether one account is affected or every account is.
            SentryContext::identify($user->id, $user->accountOwnerId());

            // After the bind, never before: accountOwner() reads the owner's
            // row, which the users policy hides until the connection knows
            // which account it is acting as.
            //
            // Checked here rather than only at login because a suspension has
            // to take effect on the tokens already issued — otherwise an
            // abusive account keeps working until its bearer token expires.
            $this->denyIfSuspended($user);
        }
    }

    /**
     * A suspended account is told why, so support has something to point at.
     * 403 rather than 401: the credentials are fine, the account is not.
     */
    private function denyIfSuspended(User $user): void
    {
        if (! $user->isSuspended()) {
            return;
        }

        abort(response()->json([
            'message' => __('auth.account_suspended'),
            'code' => 'account_suspended',
            'reason' => $user->suspensionReason(),
        ], 403));
    }
}
