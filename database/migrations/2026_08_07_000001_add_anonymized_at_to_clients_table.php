<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an erasure request was honoured for this client
 * (docs/plans/GDPR_COMPLIANCE_PLAN.md, phase 5).
 *
 * A column rather than inferring it from "the name looks like a tombstone":
 * the date is itself the record that the request was answered, which is what
 * Art. 30 documentation and any later audit actually need — and it makes the
 * endpoint idempotent without pattern-matching a display name.
 *
 * clients already carries an RLS policy, so the new column inherits it; no
 * policy change is needed. Core table, core module — the shape stays
 * identical in both editions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->timestamp('anonymized_at')->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('anonymized_at');
        });
    }
};
