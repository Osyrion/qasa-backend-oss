<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Contracts\Auth\MustVerifyEmail;

/**
 * The platform does not send mail to a third party on behalf of an account
 * whose owner has not proven the address it was registered with.
 *
 * That is the whole point of e-mail verification, and until now `verified`
 * guarded only the Taxation module — so an account created with someone
 * else's address (or a throwaway) could still e-mail invoices, payment
 * reminders, quotes and team invitations from this deployment's domain,
 * with the account's own branding on them. Filing a tax return was gated;
 * sending mail to strangers was not.
 *
 * **This is the layer that actually enforces it, not the route middleware.**
 * `verified` on the outbound routes gives an interactive caller a clean 403,
 * but the same four send paths are also reached from the scheduler
 * (GenerateRecurringInvoicesCommand, AutoRemindInvoicesCommand) and from an
 * automation listener (AutoSendInvoiceOnIssue), where no request and no
 * middleware exist. Gating only the routes would have left an unverified
 * account able to set up a recurring template and have the scheduler send
 * for it — the control has to sit where the mail is handed over.
 *
 * The **account owner** is the subject, not the caller: a team member's own
 * address is proven by the invitation they accepted (AcceptInvitationAction
 * sets email_verified_at), so the owner is the only address that can still
 * be unproven.
 */
final class VerifiedSenderGuard
{
    /**
     * Fails closed on a null owner. A send path that cannot resolve who it is
     * sending for — a closed account whose owner row is soft-deleted, say —
     * is not a case to wave through.
     *
     * @throws DomainException when the account may not send
     */
    public static function ensureCanSend(?MustVerifyEmail $owner): void
    {
        if (! config('qasa.require_verified_sender')) {
            return;
        }

        if ($owner !== null && $owner->hasVerifiedEmail()) {
            return;
        }

        throw DomainException::because(__('shared.unverified_sender'));
    }
}
