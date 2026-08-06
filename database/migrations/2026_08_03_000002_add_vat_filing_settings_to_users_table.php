<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 (docs/plans/SK_VAT_FILING_EDANE_PLAN.md) — two prerequisite
 * columns the plan's text assumes already exist and don't:
 * vat_filing_frequency (how often a VAT payer files — the period the
 * TaxFiling archive and its reminder command key off) and
 * tax_filing_reminder_enabled (opt-in toggle, same shape as
 * auto_remind_enabled — the reminder itself is a premium/Automation
 * concern, but the toggle lives on the core users table like every other
 * account-wide preference).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('vat_filing_frequency', 20)->nullable()->after('vat_status_confirmed_at');
            $table->boolean('tax_filing_reminder_enabled')->default(false)->after('vat_filing_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['vat_filing_frequency', 'tax_filing_reminder_enabled']);
        });
    }
};
