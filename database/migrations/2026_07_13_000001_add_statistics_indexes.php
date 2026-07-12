<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Statistics aggregates group by COALESCE(taxable_supply_at, issued_at)
     * (the DUZP-with-fallback date basis used throughout the BI dashboard),
     * which a plain (user_id, issued_at) index cannot serve — expression
     * indexes are needed instead. supplier_invoices_user_paid_at_idx backs
     * the DPO (days payable outstanding) health metric.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE INDEX invoices_user_effective_date_idx ON invoices (user_id, COALESCE(taxable_supply_at, issued_at))'
        );
        DB::statement(
            'CREATE INDEX supplier_invoices_user_effective_date_idx ON supplier_invoices (user_id, COALESCE(taxable_supply_at, issued_at))'
        );
        DB::statement(
            'CREATE INDEX supplier_invoices_user_paid_at_idx ON supplier_invoices (user_id, paid_at)'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS invoices_user_effective_date_idx');
        DB::statement('DROP INDEX IF EXISTS supplier_invoices_user_effective_date_idx');
        DB::statement('DROP INDEX IF EXISTS supplier_invoices_user_paid_at_idx');
    }
};
