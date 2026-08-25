<?php

declare(strict_types=1);

use App\Modules\Shared\Support\TenantPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * System exchange rates stop being writable from a tenant connection.
 *
 * `exchange_rates` is the one table whose RLS policy admitted shared rows
 * (`user_id IS NULL`) to WITH CHECK as well as USING, because the rate cache
 * fills itself: ExchangeRateService fetches a missing ČNB fixing while an
 * invoice is being issued and stores it for everybody. The cost of that
 * convenience is that the number every account's foreign-currency invoice is
 * converted with was writable from any authenticated request — no endpoint
 * does it today, but nothing in the database stopped one from starting to.
 *
 * The fill moves into upsert_system_exchange_rate(): SECURITY DEFINER, so it
 * runs as the table owner and past the policy, and narrow enough that being
 * able to call it grants nothing else — it writes one row, always with a NULL
 * user_id, and returns nothing. What the tenant connection keeps is what it
 * actually needs: reading the shared rows, and writing only its own. The
 * shape of that split — one FOR ALL policy on own rows plus a FOR SELECT one
 * on the shared rows, rather than one policy with a wide USING — is in
 * TenantPolicy::ownOrShared(), and the reason is DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = TenantPolicy::role();

        // Re-create the policies in their narrowed form. drop() also disables
        // RLS on the table; the whole migration is one transaction, so the
        // table is never readable-by-all outside it.
        TenantPolicy::drop('exchange_rates');
        TenantPolicy::ownOrShared('exchange_rates');

        // The id comes from PHP rather than gen_random_uuid(): the model
        // mints ordered UUIDv7 and a v4 mixed into that column would sort
        // out of order with every other row.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.upsert_system_exchange_rate(
                p_id uuid,
                p_base text,
                p_target text,
                p_date date,
                p_rate numeric,
                p_source text
            )
            RETURNS void
            LANGUAGE sql
            SECURITY DEFINER
            SET search_path = public
            AS $$
                INSERT INTO exchange_rates (
                    id, user_id, base_currency, target_currency, rate, date, source, created_at, updated_at
                )
                VALUES (p_id, NULL, p_base, p_target, p_rate, p_date, p_source, now(), now())
                ON CONFLICT (base_currency, target_currency, date) WHERE user_id IS NULL
                DO UPDATE SET rate = EXCLUDED.rate, source = EXCLUDED.source, updated_at = now();
            $$
        SQL);

        DB::statement('REVOKE ALL ON FUNCTION public.upsert_system_exchange_rate(uuid, text, text, date, numeric, text) FROM PUBLIC');
        DB::statement("GRANT EXECUTE ON FUNCTION public.upsert_system_exchange_rate(uuid, text, text, date, numeric, text) TO {$role}");
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.upsert_system_exchange_rate(uuid, text, text, date, numeric, text)');

        TenantPolicy::drop('exchange_rates');
        DB::statement('ALTER TABLE exchange_rates ENABLE ROW LEVEL SECURITY');
        DB::statement(sprintf(
            'CREATE POLICY %s ON exchange_rates FOR ALL TO %s USING (%s) WITH CHECK (%s)',
            TenantPolicy::NAME,
            TenantPolicy::role(),
            "user_id IS NULL OR user_id = nullif(current_setting('app.account_owner_id', true), '')::uuid",
            "user_id IS NULL OR user_id = nullif(current_setting('app.account_owner_id', true), '')::uuid",
        ));
    }
};
