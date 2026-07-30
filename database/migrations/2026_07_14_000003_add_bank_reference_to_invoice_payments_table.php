<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->string('bank_reference')->nullable()->after('note')
                ->comment('External transaction id from an imported bank statement, for import dedup');
        });

        // A cross-invoice duplicate (same transaction re-imported and matched
        // to a different invoice) is a per-account concern, checked in
        // PaymentMatchingService via a join on the account's invoices — the
        // FK here only lets us enforce uniqueness within a single invoice.
        DB::statement(
            'CREATE UNIQUE INDEX invoice_payments_invoice_id_bank_reference_unique ON invoice_payments (invoice_id, bank_reference) WHERE bank_reference IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS invoice_payments_invoice_id_bank_reference_unique');

        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropColumn('bank_reference');
        });
    }
};
