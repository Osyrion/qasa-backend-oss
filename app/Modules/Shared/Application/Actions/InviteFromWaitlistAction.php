<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Actions;

use App\Modules\Shared\Application\Notifications\WaitlistInvitationNotification;
use App\Modules\Shared\Domain\Models\WaitlistSignup;
use App\Modules\Shared\Support\WaitlistInvitationToken;
use Illuminate\Support\Facades\Notification;

/**
 * Issues an invitation against a waitlist row and mails it.
 *
 * Registration is closed during the beta (`qasa.features.registration`), so
 * the token this mints is the only way in — see AuthController::register().
 * Re-inviting is deliberately allowed and simply replaces the token: an
 * invitation that expired, or landed in a spam folder, is the ordinary case
 * rather than an error, and `invite_count` is what keeps that visible.
 */
class InviteFromWaitlistAction
{
    /**
     * @return string The raw token, for tests and for an admin who needs to
     *                hand somebody their link directly. Never stored.
     */
    public function execute(WaitlistSignup $signup): string
    {
        $token = WaitlistInvitationToken::generate();
        $expiresInDays = (int) config('qasa.waitlist.invitation_days', 14);

        $signup->forceFill([
            'invited_at' => now(),
            'invitation_token_hash' => WaitlistInvitationToken::hash($token),
            'invitation_expires_at' => now()->addDays($expiresInDays),
            'invite_count' => $signup->invite_count + 1,
        ])->save();

        // Routed by address rather than sent to a notifiable: the recipient
        // has no account here, which is the whole point of the invitation.
        Notification::route('mail', $signup->email)->notify(
            new WaitlistInvitationNotification(
                registrationUrl: $this->registrationUrl($token),
                signupLocale: $signup->locale,
                expiresInDays: $expiresInDays,
            )
        );

        return $token;
    }

    private function registrationUrl(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            .'/register?invitation='.urlencode($token);
    }
}
