<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUTOMATION_FIRST_ROADMAP_PLAN.md phase 0, principle 4: every document or
 * payment carries how it came to exist — the phase-2 automation-rate metric
 * (share of supplier invoices created and payments matched without manual
 * input) can't be measured without it. `manual` is the column default and
 * every model's fail-safe: a write path that forgets to pass a provenance
 * value is counted as "not automated" rather than silently miscounting the
 * other way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table): void {
            $table->string('provenance', 20)->default('manual')->after('status')
                ->comment('manual|email_in|api|import|auto_matched|ai_suggested — how this document came to exist');
        });

        Schema::table('invoice_payments', function (Blueprint $table): void {
            $table->string('provenance', 20)->default('manual')->after('method')
                ->comment('manual|email_in|api|import|auto_matched|ai_suggested — how this payment came to exist');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table): void {
            $table->dropColumn('provenance');
        });

        Schema::table('invoice_payments', function (Blueprint $table): void {
            $table->dropColumn('provenance');
        });
    }
};
