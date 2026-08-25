<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `unique_rate_per_day` leads with a nullable `user_id`, and Postgres
     * treats two NULLs as distinct — so the system rates, the rows that
     * every account reads, were the one shape the constraint never covered.
     * ExchangeRateService stores an on-demand ČNB rate with updateOrCreate()
     * while an invoice is being issued, which is a SELECT then an INSERT:
     * two accounts issuing on the same day both miss and both insert.
     *
     * A partial index is the narrow fix. NULLS NOT DISTINCT on the existing
     * index would also work and is one line shorter, but it would change
     * what the per-user constraint means as a side effect; this leaves that
     * index exactly as it was.
     */
    public function up(): void
    {
        // Whatever the race already produced. Keep the most recently written
        // row of each day — that is the one updateOrCreate() would have been
        // updating had the constraint existed.
        DB::statement(<<<'SQL'
            DELETE FROM exchange_rates
            WHERE user_id IS NULL
              AND id NOT IN (
                  SELECT DISTINCT ON (base_currency, target_currency, date) id
                  FROM exchange_rates
                  WHERE user_id IS NULL
                  ORDER BY base_currency, target_currency, date, updated_at DESC, id
              )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX unique_system_rate_per_day
            ON exchange_rates (base_currency, target_currency, date)
            WHERE user_id IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS unique_system_rate_per_day');
    }
};
