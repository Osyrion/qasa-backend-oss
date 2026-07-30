<?php

declare(strict_types=1);

use App\Modules\Shared\Support\TenantPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 for the core edition (docs/plans/POSTGRES_RLS_PLAN.md).
 *
 * users is the one table every other policy in this project has been able to
 * take for granted: TenantPolicy::viaParent() and every account_for_*()
 * function assume that reading users through a foreign key already respects
 * the account boundary. Until now it did not — nothing protected it at all,
 * because the first thing every one of those paths does is read it *before*
 * an account exists to bind. Login looks a user up by e-mail; Sanctum
 * resolves a token to a user. Both run on a connection with nothing set.
 *
 * The core edition has no team members — accountOwnerId() is always the
 * row's own id — so the policy here is the plain case: own('users', 'id')
 * instead of own($table), because the account a users row belongs to is its
 * own primary key, not a foreign user_id column on it. The Saas migration
 * that adds owner_id widens this policy with ALTER POLICY once team
 * membership exists to widen it for.
 *
 * Both account_for_email() and tenant_account_ids() exist for the same
 * reason account_for_public_document() does: a narrow SECURITY DEFINER
 * function that returns only an id, never a row, for the one moment nothing
 * is bound yet.
 *
 * - account_for_email() lets LoginAction and friends bind the connection
 *   before running the real, now-protected lookup — the row becomes visible
 *   because the right account is already bound by the time it's queried,
 *   not because this function returns it directly.
 * - tenant_account_ids() replaces the direct Eloquent scan
 *   TenantContext::forEachAccount() used to run against users: walking the
 *   list of accounts to bind to is itself a read of the table nothing is
 *   bound for yet.
 *
 * personal_access_tokens gets the same treatment, but composes instead of
 * duplicating: its policy is bare existence of the matching users row, no
 * account comparison of its own — precisely the viaParent() shape, except
 * one-off because the foreign key here is Sanctum's tokenable_id, not a
 * plain user_id. Whether that row is visible is entirely users' policy's
 * decision, so the two cannot disagree.
 *
 * Resolving a token still needs the same before-anything-is-bound step as
 * login: account_for_token() takes the token row's id — not secret, but
 * meaningless without the plaintext half findToken() already checked — and
 * returns the account it belongs to, or NULL for a token that isn't a
 * user's at all (Admin's own tokens: see the Admin module's migration).
 *
 * account_for_id() is the same shape again for the one caller that already
 * has a trusted user id rather than an e-mail or a token — the 2FA login
 * challenge, which the login step already verified the password for and
 * cached the id of, but which resolves on its own unauthenticated request
 * once the code is submitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = TenantPolicy::role();

        TenantPolicy::own('users', 'id');

        // deleted_at IS NULL matches the SoftDeletes global scope Eloquent
        // applies everywhere else: a deleted account's email is available
        // again and doesn't authenticate, so this function must not resolve
        // it either.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.account_for_email(p_email text)
            RETURNS uuid
            LANGUAGE sql
            SECURITY DEFINER
            SET search_path = public
            AS $$
                SELECT id FROM users WHERE email = p_email AND deleted_at IS NULL
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.tenant_account_ids()
            RETURNS SETOF uuid
            LANGUAGE sql
            SECURITY DEFINER
            SET search_path = public
            AS $$
                SELECT id FROM users
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.account_for_token(p_id bigint)
            RETURNS uuid
            LANGUAGE sql
            SECURITY DEFINER
            SET search_path = public
            AS $$
                SELECT u.id
                FROM personal_access_tokens t
                JOIN users u ON u.id = t.tokenable_id
                WHERE t.id = p_id
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.account_for_id(p_id uuid)
            RETURNS uuid
            LANGUAGE sql
            SECURITY DEFINER
            SET search_path = public
            AS $$
                SELECT id FROM users WHERE id = p_id AND deleted_at IS NULL
            $$
        SQL);

        foreach (['account_for_email(text)', 'tenant_account_ids()', 'account_for_token(bigint)', 'account_for_id(uuid)'] as $signature) {
            DB::statement("REVOKE ALL ON FUNCTION public.{$signature} FROM PUBLIC");
            DB::statement("GRANT EXECUTE ON FUNCTION public.{$signature} TO {$role}");
        }

        DB::statement('ALTER TABLE personal_access_tokens ENABLE ROW LEVEL SECURITY');
        DB::statement(<<<SQL
            CREATE POLICY tenant_isolation ON personal_access_tokens
                FOR ALL TO {$role}
                USING (EXISTS (SELECT 1 FROM users WHERE users.id = personal_access_tokens.tokenable_id))
                WITH CHECK (EXISTS (SELECT 1 FROM users WHERE users.id = personal_access_tokens.tokenable_id))
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON personal_access_tokens');
        DB::statement('ALTER TABLE personal_access_tokens DISABLE ROW LEVEL SECURITY');

        DB::statement('DROP FUNCTION IF EXISTS public.account_for_id(uuid)');
        DB::statement('DROP FUNCTION IF EXISTS public.account_for_token(bigint)');
        DB::statement('DROP FUNCTION IF EXISTS public.tenant_account_ids()');
        DB::statement('DROP FUNCTION IF EXISTS public.account_for_email(text)');

        TenantPolicy::drop('users');
    }
};
