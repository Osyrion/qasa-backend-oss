<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Support\Facades\DB;

/**
 * Which account, if any, an e-mail address belongs to.
 *
 * Login and registration both need to read `users` before an account exists
 * to bind — the whole reason that table needed the same SECURITY DEFINER
 * treatment public document links and Stripe Connect already had (see the
 * phase 7 migrations, docs/plans/POSTGRES_RLS_PLAN.md). The function itself
 * never returns a row, only an id: the actual user row is read afterwards,
 * through the now-bound connection, by whichever query needed it in the
 * first place.
 */
final class AccountLookup
{
    /**
     * Bind the connection to the account that owns $email, if any.
     *
     * A no-op when the address is unknown — the caller's follow-up query
     * then sees nothing, same as it would have before this existed.
     */
    public static function bindByEmail(?string $email): void
    {
        if ($email === null) {
            return;
        }

        $account = self::byEmail($email);

        if ($account !== null) {
            TenantContext::set($account);
        }
    }

    /**
     * The account owning $email, without binding anything. For existence
     * checks — "is this address already taken" — that don't need the row.
     */
    public static function byEmail(string $email): ?string
    {
        /** @var object{account: string|null}|null $row */
        $row = DB::selectOne('SELECT public.account_for_email(?) AS account', [$email]);

        return $row?->account;
    }

    /**
     * The account that has *verified* $phone, without binding anything.
     *
     * Unverified numbers are invisible here on purpose — see the function's
     * own migration. Used by PhoneAvailable to keep one number from
     * unlocking a trial on account after account.
     */
    public static function byPhone(string $phone): ?string
    {
        /** @var object{account: string|null}|null $row */
        $row = DB::selectOne('SELECT public.account_for_phone(?) AS account', [$phone]);

        return $row?->account;
    }

    /**
     * Bind the connection to the account owning user id $id, if any.
     *
     * For callers that already hold a trusted id — not from user input, but
     * from somewhere the id was put there by already-authenticated code,
     * such as a 2FA challenge cached at the end of a successful password
     * check — and now need to read that user back on a later, unauthenticated
     * request.
     */
    public static function bindById(string $id): void
    {
        /** @var object{account: string|null}|null $row */
        $row = DB::selectOne('SELECT public.account_for_id(?) AS account', [$id]);

        if ($row?->account !== null) {
            TenantContext::set($row->account);
        }
    }

    /**
     * Bind the connection to the account owning Stripe customer id
     * $stripeId, if any. Stripe webhooks carry only their own ids —
     * Cashier's billable lookup, and this app's own listeners, both need
     * the account bound before they can find the row at all.
     */
    public static function bindByStripeId(string $stripeId): void
    {
        /** @var object{account: string|null}|null $row */
        $row = DB::selectOne('SELECT public.account_for_stripe_customer(?) AS account', [$stripeId]);

        if ($row?->account !== null) {
            TenantContext::set($row->account);
        }
    }
}
