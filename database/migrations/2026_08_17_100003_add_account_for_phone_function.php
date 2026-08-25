<?php

declare(strict_types=1);

use App\Modules\Shared\Support\TenantPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Is this number already verified by somebody else?"
 *
 * The same shape, and the same reason, as account_for_email() in the phase 7
 * migration: `users` is tenant-scoped, and the account asking is not the
 * account that would own the answer, so an ordinary query sees nothing and
 * would report every number as free. A narrow SECURITY DEFINER function that
 * returns an id and never a row is the established way to ask a
 * cross-account existence question here.
 *
 * Three conditions, each load-bearing:
 *
 * - phone_verified_at IS NOT NULL — an unverified number is just text
 *   somebody typed into their profile, and two accounts are welcome to have
 *   typed the same one. Only a proved claim reserves a number.
 * - deleted_at IS NULL — matches the SoftDeletes scope every other lookup
 *   here honours: a closed account must not keep its number hostage.
 * - LIMIT is unnecessary — the verify path is what enforces uniqueness going
 *   forward, and pre-existing duplicates are impossible because no row could
 *   have been verified before this migration ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = TenantPolicy::role();

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

        DB::statement('REVOKE ALL ON FUNCTION public.account_for_phone(text) FROM PUBLIC');
        DB::statement("GRANT EXECUTE ON FUNCTION public.account_for_phone(text) TO {$role}");
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.account_for_phone(text)');
    }
};
