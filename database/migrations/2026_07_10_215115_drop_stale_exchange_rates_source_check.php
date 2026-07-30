<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The `source` column started as an enum(), which Postgres implements as a
     * plain varchar plus a named CHECK constraint. The earlier migration that
     * widened it to string() via ->change() only altered the column type, so
     * the old "exchange_rates_source_check" constraint (manual|ecb|fixer)
     * stayed behind — rejecting the newer 'cnb' value the ExchangeRateSource
     * enum allows.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE exchange_rates DROP CONSTRAINT IF EXISTS exchange_rates_source_check');
    }

    public function down(): void
    {
        // Allowed values are enforced by the ExchangeRateSource PHP enum; no need to restore the DB-level constraint.
    }
};
