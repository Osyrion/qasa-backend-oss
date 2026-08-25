<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Services;

use App\Modules\Shared\Domain\Models\WaitlistSignup;
use App\Modules\Shared\Support\WaitlistInvitationToken;

/**
 * The read side of an invitation: registration hands back a raw token and
 * needs to know whether it opens the door, and for whom.
 *
 * Kept apart from InviteFromWaitlistAction because the two run in different
 * worlds — issuing is an authenticated admin operation, redeeming happens on
 * a public endpoint with nothing but a string from a URL.
 */
class WaitlistInvitations
{
    public function findUsable(?string $rawToken): ?WaitlistSignup
    {
        if ($rawToken === null || $rawToken === '') {
            return null;
        }

        $signup = WaitlistSignup::query()
            ->where('invitation_token_hash', WaitlistInvitationToken::hash($rawToken))
            ->first();

        if (! $signup instanceof WaitlistSignup || ! $signup->hasUsableInvitation()) {
            return null;
        }

        return $signup;
    }

    /**
     * Closes the invitation out once its account exists.
     *
     * The token is cleared rather than merely marked used: an invitation is
     * for one account, and a live token on a registered row is a link that
     * still works after it should not.
     *
     * Two requests racing on the same token can both get past findUsable().
     * Nothing here needs to lock for that — the address is pinned to the
     * invitation (see AuthController::register) and `users.email` is unique,
     * so the second one loses on the way in.
     */
    public function markRegistered(WaitlistSignup $signup): void
    {
        $signup->forceFill([
            'registered_at' => now(),
            'invitation_token_hash' => null,
        ])->save();
    }
}
