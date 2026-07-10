<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `source` column started as an enum(), which Postgres implements as a
     * plain varchar plus a named CHECK constraint. The earlier migration that
     * widened the column to string() via ->change() rebuilds the column on
     * SQLite (dropping the inline CHECK for free) but on Postgres it only
     * alters the column type, leaving the old
     * "exchange_rates_source_check" constraint (manual|ecb|fixer) in place —
     * rejecting the newer 'cnb' value enforced by the ExchangeRateSource enum.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE exchange_rates DROP CONSTRAINT IF EXISTS exchange_rates_source_check');
        }
    }

    public function down(): void
    {
        // Allowed values are enforced by the ExchangeRateSource PHP enum; no need to restore the DB-level constraint.
    }
};
