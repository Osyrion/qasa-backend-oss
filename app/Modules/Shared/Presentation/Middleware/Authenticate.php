<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Middleware;

use App\Modules\Auth\Domain\Models\User;
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

        $user = $this->auth->user();

        if ($user instanceof User) {
            TenantContext::set($user->accountOwnerId());
        }
    }
}
