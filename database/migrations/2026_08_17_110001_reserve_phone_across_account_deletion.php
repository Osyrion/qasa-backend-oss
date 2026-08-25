<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A verified number belongs to one account, and deleting that account does
 * not immediately hand it to the next one.
 *
 * The first version of account_for_phone() copied account_for_email()
 * wholesale, `deleted_at IS NULL` included. For an e-mail address that
 * condition is right: closing an account has to release the address so the
 * same person can register again. For a phone number it was the whole
 * feature undone — verify, collect the trial, delete the account, register
 * again with the same handset, collect another trial, with no waiting at
 * all. The number is the anti-abuse anchor precisely because it is scarce,
 * and a condition that frees it on demand makes it free.
 *
 * A soft-deleted account still exists (qasa:accounts:purge only anonymises
 * it once gdpr.account_purge_grace_days has run out), so its number stays
 * spoken for through the grace period. The purge then nulls `phone`, which
 * releases it here — that release is deliberate and required: the number is
 * personal data and cannot be retained indefinitely just to keep a slot
 * occupied.
 *
 * What survives the purge instead is trial_phone_claims (Saas module): a
 * peppered hash and a date, which is what actually enforces "one trial per
 * number, ever" without keeping the number itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.account_for_phone(p_phone text)
            RETURNS uuid
            LANGUAGE sql
            SECURITY DEFINER
            SET search_path = public
            AS $$
                SELECT id FROM users
                WHERE phone = p_phone
                  AND phone_verified_at IS NOT NULL
                ORDER BY deleted_at NULLS FIRST
                LIMIT 1
            $$
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.account_for_phone(p_phone text)
            RETURNS uuid
            LANGUAGE sql
            SECURITY DEFINER
            SET search_path = public
            AS $$
                SELECT id FROM users
                WHERE phone = p_phone
                  AND phone_verified_at IS NOT NULL
                  AND deleted_at IS NULL
            $$
        SQL);
    }
};
